<?php

namespace App\Http\Controllers\API;

use App\Exceptions\BasketStockException;
use App\Exceptions\BasketValidationException;
use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Services\OperationService;
use App\Services\TransactionSearchParser;
use App\Support\ActivityLogger;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OperationController extends Controller
{
    private const PAYMENT_METHODS = ['especes', 'orange_money', 'moov_money', 'wave', 'mtn_money', 'virement', 'autre'];

    /**
     * Même garde que TransactionController::rollbackAnyOpenTransaction() —
     * voir ce commentaire pour le contexte (état de transaction hérité
     * d'une connexion réutilisée).
     */
    private function rollbackAnyOpenTransaction(): void
    {
        try {
            DB::statement('ROLLBACK');
        } catch (\Throwable $e) {
            // Best-effort.
        }
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?Operation
    {
        return Operation::where('user_id', CurrentAccount::id())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function isIdempotencyKeyConflict(\Illuminate\Database\QueryException $e, ?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null) {
            return false;
        }

        $sqlStateIndicatesIntegrityViolation = in_array((string) $e->getCode(), ['23505', '23000'], true);

        return $sqlStateIndicatesIntegrityViolation && str_contains($e->getMessage(), 'idempotency_key');
    }

    private function loadOperationForResponse(Operation $operation): Operation
    {
        $operation->load([
            'transactions' => function ($q) {
                $q->orderBy('created_at');
            },
            'transactions.article',
            'transactions.variation',
            'client',
            'createdBy',
        ]);

        $operation->transactions->each(function ($transaction) {
            $transaction->article?->makeHidden('user');
            $transaction->variation?->makeHidden('article');
        });

        // "Caissier : {nom}" sur le ticket de caisse — voir le commentaire de
        // l'accesseur sur le modèle pour pourquoi ce n'est pas dans $appends.
        $operation->append(['author_name', 'created_by']);
        $operation->makeHidden(['createdBy', 'user']);

        return $operation;
    }

    /**
     * POST /api/operations — encaisse un panier (vente multi-articles) ou
     * enregistre une dépense/un apport/un retrait à une seule ligne, via
     * OperationService::create() (voir ce service pour le verrouillage du
     * stock, la fusion des lignes dupliquées et le calcul espèces).
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type');

            // Une permission par type d'opération — jamais de création
            // "générique" : un caissier sans expenses.create ne peut pas
            // enregistrer une dépense même s'il sait créer une vente.
            $requiredPermission = match ($type) {
                'sale' => Permissions::SALES_CREATE,
                'expense' => Permissions::EXPENSES_CREATE,
                'apport', 'retrait' => Permissions::DEPOSITS_MANAGE,
                default => null,
            };
            if ($requiredPermission && Auth::user()->cannot($requiredPermission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:sale,expense,apport,retrait',
                'payment_method' => [
                    $type === 'sale' || $type === 'apport' || $type === 'retrait' ? 'required' : 'nullable',
                    'in:' . implode(',', self::PAYMENT_METHODS),
                ],
                'occurred_at' => 'nullable|date',
                'client_id' => 'nullable|string|exists:clients,id,user_id,' . CurrentAccount::id(),
                'note' => 'nullable|string|max:2000',
                'amount_received' => 'nullable|numeric|min:0',
                'lines' => 'required|array|min:1|max:100',
                'lines.*.article_id' => $type === 'sale' ? 'required|string|exists:articles,id' : 'prohibited',
                'lines.*.variation_id' => 'nullable|string|exists:variations,id',
                'lines.*.quantity' => $type === 'sale' ? 'required|integer|min:1' : 'prohibited',
                'lines.*.unit_price' => $type === 'sale' ? 'required|numeric|min:0' : 'prohibited',
                'lines.*.amount' => $type === 'sale' ? 'prohibited' : 'required|numeric|gt:0',
                'lines.*.name' => $type === 'expense' ? 'required|string|max:255' : 'nullable|string|max:255',
                'lines.*.funding_source' => $type === 'apport' ? 'required|in:apport_personnel,collaborateur,pret,autre' : 'prohibited',
                'lines.*.collaborator_id' => 'nullable|string|exists:collaborators,id,user_id,' . CurrentAccount::id(),
                'lines.*.note' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($type === 'apport') {
                foreach ($request->input('lines') as $i => $line) {
                    if (($line['funding_source'] ?? null) === 'collaborateur' && empty($line['collaborator_id'])) {
                        return response()->json([
                            'success' => false,
                            'message' => "collaborator_id est obligatoire quand funding_source=collaborateur (ligne " . ($i + 1) . ')',
                        ], 422);
                    }
                }
            }

            $idempotencyKey = $request->header('Idempotency-Key');

            if (!$idempotencyKey) {
                return response()->json([
                    'success' => false,
                    'message' => "L'en-tête Idempotency-Key est obligatoire",
                ], 422);
            }

            if (!Str::isUuid($idempotencyKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'En-tête Idempotency-Key invalide (UUID attendu)',
                ], 422);
            }

            $this->rollbackAnyOpenTransaction();

            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Opération déjà enregistrée',
                    'data' => $this->loadOperationForResponse($existing),
                ], 200);
            }

            $lines = array_map(function ($line) use ($type) {
                if ($type === 'apport') {
                    $line['collaborator_id'] = ($line['funding_source'] ?? null) === 'collaborateur'
                        ? ($line['collaborator_id'] ?? null)
                        : null;
                } elseif ($type === 'retrait') {
                    $line['name'] = $line['name'] ?? 'Retrait';
                } elseif ($type !== 'sale' && empty($line['name'])) {
                    $line['name'] = $type === 'apport' ? 'Apport de fonds' : ($line['name'] ?? '');
                }

                return $line;
            }, $request->input('lines'));

            try {
                $operation = DB::transaction(function () use ($request, $type, $lines, $idempotencyKey) {
                    return OperationService::create([
                        'user_id' => CurrentAccount::id(),
                        'created_by_user_id' => CurrentAccount::actorId(),
                        'type' => $type,
                        'payment_method' => $request->input('payment_method'),
                        'occurred_at' => $request->input('occurred_at'),
                        'client_id' => $request->input('client_id'),
                        'note' => $request->input('note'),
                        'amount_received' => $request->input('amount_received'),
                        'idempotency_key' => $idempotencyKey,
                        'lines' => $lines,
                    ]);
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Opération enregistrée avec succès',
                    'data' => $this->loadOperationForResponse($operation),
                ], 201);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!$this->isIdempotencyKeyConflict($e, $idempotencyKey)) {
                    throw $e;
                }

                $existing = $this->findByIdempotencyKey($idempotencyKey);
                if (!$existing) {
                    throw $e;
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Opération déjà enregistrée',
                    'data' => $this->loadOperationForResponse($existing),
                ], 200);
            }
        } catch (BasketStockException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant',
                'lines' => $e->lines,
            ], 422);
        } catch (BasketValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la création de l'opération", [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la création de l'opération",
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        $operation = Operation::where('id', $id)->where('user_id', CurrentAccount::id())->first();

        if (!$operation) {
            return response()->json([
                'success' => false,
                'message' => 'Opération non trouvée',
            ], 404);
        }

        if (Auth::user()->cannot('view', $operation)) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé à consulter cette opération',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Opération récupérée avec succès',
            'data' => $this->loadOperationForResponse($operation),
        ]);
    }

    /**
     * GET /api/operations?q=&type=&from=&to=&page=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:100',
            'type' => 'nullable|in:sale,expense,apport,retrait',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'created_by' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Paramètres de recherche invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $canViewAll = Auth::user()->can(Permissions::SALES_VIEW_ALL);
        $canViewOwn = Auth::user()->can(Permissions::SALES_VIEW_OWN);

        if (!$canViewAll && !$canViewOwn) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $query = Operation::where('user_id', CurrentAccount::id());

            // sales.view_own (sans sales.view_all) : un membre ne voit que
            // les opérations qu'IL a créées — jamais celles des autres
            // membres du même compte (ex. caissier A ne voit pas les
            // ventes du caissier B).
            if (!$canViewAll) {
                $query->where('created_by_user_id', CurrentAccount::actorId());
            } elseif ($request->filled('created_by')) {
                // created_by=... n'a de sens QUE pour qui peut voir toute
                // l'équipe (sales.view_all) — un membre restreint à ses
                // propres ventes est déjà filtré ci-dessus, le paramètre
                // serait sinon un moyen détourné de voir les ventes d'un
                // autre membre.
                $query->where('created_by_user_id', $request->input('created_by'));
            }

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            $timezone = 'Africa/Abidjan';
            $q = $request->filled('q') ? trim((string) $request->input('q')) : '';
            $codeLikePattern = null;

            if ($q !== '') {
                $codeLikePattern = $this->applyOperationSearch($query, $q, $timezone);
            }

            if ($request->filled('from')) {
                $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m-d', $request->input('from'), $timezone)->startOfDay());
            }
            if ($request->filled('to')) {
                $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m-d', $request->input('to'), $timezone)->endOfDay());
            }

            if ($codeLikePattern !== null) {
                // La correspondance de code (complète OU par suffixe, sans
                // tirets, insensible à la casse) passe en premier, puis par
                // date décroissante. upper()/replace() sont standard SQL,
                // identiques sur SQLite et Postgres. Un pattern SANS '%'
                // (code complet) se comporte comme une égalité stricte.
                $query->orderByRaw("CASE WHEN upper(replace(code, '-', '')) LIKE ? THEN 0 ELSE 1 END", [$codeLikePattern])
                    ->orderBy('occurred_at', 'desc');
            } else {
                $query->orderBy('occurred_at', 'desc');
            }

            $total = (clone $query)->count();

            // Pas de restriction de colonnes sur 'createdBy' (ex.
            // 'createdBy:id,...') : certains fichiers de test (schéma
            // minimal, voir tests/Feature/*.php) n'ont pas encore les
            // colonnes account_id/role sur `users`, ce qui ferait échouer
            // la requête d'eager-load sur Postgres (strict sur les
            // colonnes sélectionnées, contrairement à SQLite). Sans
            // conséquence : la relation est entièrement masquée ci-dessous
            // (makeHidden), seul l'accesseur created_by (léger) est exposé.
            $eagerLoad = ['transactions.article', 'transactions.variation', 'createdBy'];

            $paginationRequested = $request->filled('page') || $request->filled('per_page');
            if ($paginationRequested) {
                $page = max(1, (int) $request->input('page', 1));
                $perPage = max(1, (int) $request->input('per_page', 20));
                $operations = $query->with($eagerLoad)
                    ->forPage($page, $perPage)->get();
            } else {
                $operations = $query->with($eagerLoad)->get();
                $page = 1;
                $perPage = max($total, 1);
            }

            $operations->each(function (Operation $operation) {
                $operation->transactions->each(function ($transaction) {
                    $transaction->article?->makeHidden('user');
                    $transaction->variation?->makeHidden('article');
                });
                $operation->append('created_by');
                $operation->makeHidden('createdBy');
            });

            return response()->json([
                'success' => true,
                'message' => 'Opérations récupérées avec succès',
                'data' => $operations,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des opérations',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reconnaît un code d'opération dans `q` (avec ou sans tirets, insensible
     * à la casse : "V-261026-0042", "v2610260042") et, à défaut, une
     * recherche sur les 4 derniers chiffres seuls ("0042"). Complète
     * TOUJOURS avec la recherche texte habituelle (nom de produit via les
     * lignes, note...) — un faux positif de format de code dégraderait
     * silencieusement une vraie recherche texte sinon.
     *
     * @return string|null Le code normalisé (sans tirets, majuscules) à
     *   utiliser pour le tri "correspondance exacte en premier", si `q`
     *   ressemble à un code complet — null sinon.
     */
    private function applyOperationSearch($query, string $q, string $timezone): ?string
    {
        $exactCode = TransactionSearchParser::parseOperationCode($q);
        if ($exactCode !== null) {
            $query->whereRaw("upper(replace(code, '-', '')) = ?", [$exactCode]);
            return $exactCode;
        }

        if (TransactionSearchParser::isCodeSuffix($q)) {
            // Fragment purement numérique court : recherche par SUFFIXE de
            // code (les 4 derniers chiffres du compteur), cumulée au texte —
            // une note/un nom de produit qui contient ce nombre doit aussi
            // remonter, mais après les vraies correspondances de code (voir
            // le CASE de tri dans index()).
            $suffix = trim($q);
            $likePattern = '%' . $suffix;
            $query->where(function ($outer) use ($likePattern, $q, $timezone) {
                $outer->whereRaw("upper(code) LIKE ?", [$likePattern])
                    ->orWhere(function ($textGroup) use ($q, $timezone) {
                        $this->applyOperationTextSearch($textGroup, $q, $timezone);
                    });
            });
            return $likePattern;
        }

        $this->applyOperationTextSearch($query, $q, $timezone);
        return null;
    }

    /**
     * Même logique de parsing que TransactionController::applyTransactionSearch()
     * (date/montant/texte), appliquée aux opérations — le texte cherche dans
     * le nom/la variation des ARTICLES DE SES LIGNES (transactions), dans la
     * note de l'opération, et dans le client lié.
     */
    private function applyOperationTextSearch($query, string $q, string $timezone): void
    {
        $parsed = TransactionSearchParser::parse($q, $timezone);

        if ($parsed['kind'] === 'day') {
            $query->whereBetween('occurred_at', [
                $parsed['date']->copy()->startOfDay(),
                $parsed['date']->copy()->endOfDay(),
            ]);
            return;
        }

        if ($parsed['kind'] === 'month') {
            $query->whereBetween('occurred_at', [$parsed['start'], $parsed['end']]);
            return;
        }

        $this->registerSqliteUnaccentIfNeeded();

        if ($parsed['kind'] === 'amount') {
            $amount = $parsed['amount'];
            $words = preg_split('/\s+/', $parsed['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $query->where(function ($outer) use ($amount, $words) {
                $outer->where('total_amount', $amount);
                if ($words !== []) {
                    $outer->orWhere(function ($textGroup) use ($words) {
                        foreach ($words as $word) {
                            $this->applyOperationSearchWord($textGroup, $word);
                        }
                    });
                }
            });
            return;
        }

        foreach ($parsed['words'] as $word) {
            $this->applyOperationSearchWord($query, $word);
        }
    }

    private function applyOperationSearchWord($query, string $word): void
    {
        $escaped = $this->escapeSearchWord($word);
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $wrap = fn (string $column) => $isPg
            ? "unaccent({$column}) ILIKE unaccent(?)"
            : "lower(unaccent({$column})) LIKE lower(?)";

        $query->where(function ($q) use ($escaped, $wrap) {
            $q->whereRaw($wrap('operations.note'), [$escaped])
                ->orWhereHas('client', function ($q2) use ($escaped, $wrap) {
                    $q2->whereRaw($wrap('clients.name'), [$escaped]);
                })
                ->orWhereHas('transactions', function ($q2) use ($escaped, $wrap) {
                    $q2->where(function ($q3) use ($escaped, $wrap) {
                        $q3->whereRaw($wrap('transactions.name'), [$escaped])
                            ->orWhereHas('article', function ($q4) use ($escaped, $wrap) {
                                $q4->whereRaw($wrap('articles.name'), [$escaped]);
                            })
                            ->orWhereHas('variation', function ($q4) use ($escaped, $wrap) {
                                $q4->whereRaw($wrap('variations.name'), [$escaped]);
                            });
                    });
                });
        });
    }

    private function escapeSearchWord(string $word): string
    {
        return '%' . addcslashes($word, '\\%_') . '%';
    }

    private function registerSqliteUnaccentIfNeeded(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::connection()->getPdo()->sqliteCreateFunction(
            'unaccent',
            fn (?string $value) => $value === null ? null : TransactionSearchParser::stripAccents($value),
            1
        );
    }

    /**
     * DELETE /api/operations/{id} — supprime l'opération ET toutes ses
     * lignes (soft delete), remonte le stock (automatique, voir
     * OperationService::delete()) et resynchronise les articles variables.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $operation = Operation::where('id', $id)->first();

            if (!$operation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opération non trouvée',
                ], 404);
            }

            if (Auth::user()->cannot('delete', $operation)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé à supprimer cette opération',
                ], 403);
            }

            if ($operation->invoice_id) {
                $invoice = $operation->invoice;
                if ($invoice && $invoice->status === 'paid') {
                    return response()->json([
                        'success' => false,
                        'message' => "Cette opération est liée à la facture {$invoice->invoice_number}. Annulez ou modifiez la facture.",
                        'invoice_id' => $invoice->id,
                    ], 422);
                }
            }

            $this->rollbackAnyOpenTransaction();

            DB::transaction(function () use ($operation) {
                OperationService::delete($operation);
            });

            ActivityLogger::log(Auth::id(), 'operation.deleted', Operation::class, $operation->id, [
                'code' => $operation->code,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Opération supprimée avec succès',
            ]);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression de l'opération", [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la suppression de l'opération",
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function restore(string $id): JsonResponse
    {
        try {
            $operation = Operation::withTrashed()->where('id', $id)->first();

            if (!$operation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opération non trouvée',
                ], 404);
            }

            if (Auth::user()->cannot('restore', $operation)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé à restaurer cette opération',
                ], 403);
            }

            $this->rollbackAnyOpenTransaction();

            $operation = DB::transaction(function () use ($operation) {
                OperationService::restore($operation);
                return $operation;
            });

            ActivityLogger::log(Auth::id(), 'operation.restored', Operation::class, $operation->id, [
                'code' => $operation->code,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Opération restaurée avec succès',
                'data' => $this->loadOperationForResponse($operation->fresh()),
            ]);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la restauration de l'opération", [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la restauration de l'opération",
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
