<?php

namespace App\Http\Controllers\API;

use App\Exceptions\BasketStockException;
use App\Exceptions\BasketValidationException;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Transaction;
use App\Models\Variation;
use App\Services\NotificationService;
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

class TransactionController extends Controller
{
    /**
     * Garde défensive avant toute DB::transaction() d'écriture : nettoie un
     * état de transaction hérité (ex: connexion réutilisée par un proxy
     * réseau qui rattache une session Postgres déjà "avortée" — SQLSTATE
     * 25P02 observé en prod). ROLLBACK hors transaction est un no-op côté
     * Postgres (simple avertissement), donc sans risque si tout est propre.
     */
    private function rollbackAnyOpenTransaction(): void
    {
        try {
            DB::statement('ROLLBACK');
        } catch (\Throwable $e) {
            // Best-effort : une erreur ici ne doit jamais empêcher la vraie
            // opération de continuer.
        }
    }

    /**
     * Recherche une transaction déjà créée par CET utilisateur avec cette
     * clé d'idempotence exacte. Contrairement à l'ancienne heuristique par
     * ressemblance de champs (fenêtre de temps + mêmes valeurs), une clé
     * d'idempotence ne produit jamais de faux positif : deux soumissions
     * distinctes de l'utilisateur ont toujours des clés différentes, même
     * si leurs champs métier sont identiques (ex: deux ventes du même
     * article à quelques secondes d'intervalle restent deux transactions).
     * Seuls les retries automatiques d'UNE MÊME soumission (même clé,
     * générée une fois côté client et conservée pour toutes ses tentatives)
     * sont détectés ici.
     */
    private function findTransactionByIdempotencyKey(string $idempotencyKey): ?Transaction
    {
        return Transaction::where('user_id', CurrentAccount::id())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Détermine si une QueryException correspond précisément à une violation
     * de la contrainte unique (user_id, idempotency_key) — c'est-à-dire une
     * course entre deux écritures concurrentes d'UNE MÊME soumission (la
     * vérification préalable n'avait rien trouvé, mais une autre requête a
     * créé la ligne entre-temps). Isolée dans sa propre méthode pour être
     * testable indépendamment d'une vraie violation de contrainte en base.
     *
     * Volontairement conservateur : exige à la fois un code SQLSTATE
     * d'intégrité ET la présence du nom de colonne dans le message, pour ne
     * jamais absorber une QueryException sans rapport (perte de connexion,
     * autre contrainte) qui doit continuer à remonter en erreur 500.
     */
    private function isIdempotencyKeyConflict(\Illuminate\Database\QueryException $e, ?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null) {
            return false;
        }

        // (string) : PDO/QueryException::getCode() renvoie parfois un int,
        // parfois une string selon le driver (observé : int sous SQLite,
        // string "23505" sous Postgres) — comparaison stricte normalisée
        // pour ne pas dépendre de ce détail.
        $sqlStateIndicatesIntegrityViolation = in_array((string) $e->getCode(), ['23505', '23000'], true);

        return $sqlStateIndicatesIntegrityViolation
            && str_contains($e->getMessage(), 'idempotency_key');
    }

    /**
     * Message de succès par type — centralisé pour ne pas dupliquer ce
     * ternaire aux 3 endroits qui répondent après une création réussie
     * (création normale, rejeu d'idempotence détecté avant écriture, rejeu
     * détecté après conflit de contrainte unique).
     */
    private function successMessageFor(string $type): string
    {
        return match ($type) {
            'sale' => 'Vente enregistrée avec succès',
            'expense' => 'Dépense enregistrée avec succès',
            'apport' => 'Apport enregistré avec succès',
            'retrait' => 'Retrait enregistré avec succès',
            default => 'Transaction enregistrée avec succès',
        };
    }

    /**
     * Précharge ce qu'il faut pour la réponse JSON d'une transaction "sale"
     * (article + sa relation user.settings, nécessaire à
     * Article::getLowStockAttribute() pour éviter son propre lazy load),
     * puis retire l'objet User complet de la réponse — il a 8 accessors
     * "appended" coûteux (plusieurs avec leur propre requête SQL), jamais
     * voulus ici.
     */
    private function loadTransactionForResponse(Transaction $transaction): Transaction
    {
        $transaction->load([
            'article' => function ($query) {
                $query->withSum(['transactions' => function ($q) {
                    $q->where('type', 'sale');
                }], 'quantity');
            },
            'article.user.settings',
            'variation',
            'operation',
        ]);
        $transaction->article?->makeHidden('user');

        return $transaction;
    }

    /**
     * Display a listing of the transactions for the authenticated user.
     */
    /**
     * `q`/`date_from`/`date_to` sont additifs et OPTIONNELS : un appel sans
     * AUCUN paramètre garde exactement le comportement historique (liste
     * complète, pas de pagination) — le tableau de bord ("Dernières
     * opérations", voir useTransactionsQuery() côté front) appelle cet
     * endpoint ainsi et ne doit jamais être affecté par cette fonctionnalité.
     * `meta` (total/page/per_page/total_pages) est en revanche TOUJOURS
     * présent, y compris sans pagination (page=1, per_page=total) : c'est
     * un ajout pur, ignoré par tout code qui ne le lit pas encore.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:100',
            'type' => 'nullable|in:sale,expense,apport,retrait',
            'payment_method' => 'nullable|in:especes,orange_money,moov_money,wave,mtn_money,virement,autre,non_precise',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
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
            $withSoldQuantity = function ($query) {
                $query->withSum(['transactions' => function ($q) {
                    $q->where('type', 'sale');
                }], 'quantity');
            };

            $query = Transaction::where('user_id', CurrentAccount::id())
                ->when(!$canViewAll, function ($q) {
                    // sales.view_own (sans sales.view_all) : uniquement les
                    // transactions dont l'opération a été créée par CE
                    // membre — jamais celles d'un autre membre du compte.
                    $q->whereHas('operation', function ($opQuery) {
                        $opQuery->where('created_by_user_id', CurrentAccount::actorId());
                    });
                })
                ->with([
                    // Précharge la relation utilisée par Article::getLowStockAttribute()
                    // (appended, donc calculée pour CHAQUE article à la sérialisation) :
                    // sans ça, relationLoaded('user') est faux pour chaque instance et
                    // déclenche un ->load('user.settings') par transaction (N+1 massif).
                    'article' => $withSoldQuantity,
                    'article.user.settings',
                    // Variation::getLowStockAttribute()/getSoldQuantityAttribute() ont
                    // exactement le même besoin (relationLoaded('article') + withSum),
                    // sans quoi le même N+1 se reproduit côté variation.
                    'variation' => $withSoldQuantity,
                    'variation.article.user.settings',
                    // operation_code (appended, voir Transaction::getOperationCodeAttribute()).
                    'operation',
                ]);

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->filled('payment_method')) {
                // 'non_precise' = aucun moyen de paiement enregistré (anciennes
                // ventes, avant l'ajout de ce champ) — même convention que
                // AnalyticsSummaryService (COALESCE(payment_method, 'non_precise')).
                if ($request->input('payment_method') === 'non_precise') {
                    $query->whereNull('payment_method');
                } else {
                    $query->where('payment_method', $request->input('payment_method'));
                }
            }

            $timezone = 'Africa/Abidjan';

            $q = $request->filled('q') ? trim((string) $request->input('q')) : '';
            $codeLikePattern = null;
            if ($q !== '') {
                $codeLikePattern = $this->applyTransactionSearch($query, $q, $timezone);
            }

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $request->input('date_from'), $timezone)->startOfDay());
            }
            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $request->input('date_to'), $timezone)->endOfDay());
            }

            if ($codeLikePattern !== null) {
                // Une transaction n'a pas son propre code — celui de son
                // opération (sous-requête corrélée, standard SQL) décide du
                // tri ; une correspondance de code passe avant la date.
                $query->orderByRaw(
                    "CASE WHEN (SELECT upper(replace(o.code, '-', '')) FROM operations o WHERE o.id = transactions.operation_id) LIKE ? THEN 0 ELSE 1 END",
                    [$codeLikePattern]
                )->orderBy('created_at', 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $total = (clone $query)->count();

            $paginationRequested = $request->filled('page') || $request->filled('per_page');
            if ($paginationRequested) {
                $page = max(1, (int) $request->input('page', 1));
                $perPage = max(1, (int) $request->input('per_page', 20));
                $transactions = $query->forPage($page, $perPage)->get();
            } else {
                $transactions = $query->get();
                $page = 1;
                $perPage = max($total, 1);
            }

            // article.user.settings/variation.article.user.settings ne sont chargés
            // QUE pour alimenter les accessors low_stock/sales_percentage en mémoire
            // (évite le lazy load) — le User complet ne doit pas se retrouver dans la
            // réponse JSON : il a lui-même 8 accessors "appended" (dont plusieurs avec
            // leur propre requête SQL, ex. getTotalStockValueAttribute()), qui
            // s'exécuteraient sinon une fois PAR TRANSACTION à la sérialisation.
            $transactions->each(function (Transaction $transaction) {
                $transaction->article?->makeHidden('user');
                $transaction->variation?->article?->makeHidden('user');
            });

            return response()->json([
                'success' => true,
                'message' => 'Transactions récupérées avec succès',
                'data' => $transactions,
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
                'message' => 'Erreur lors de la récupération des transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reconnaît d'abord un code d'opération (complet ou par suffixe — voir
     * TransactionSearchParser::parseOperationCode()/isCodeSuffix()) avant
     * d'interpréter `q` normalement (date/montant/texte, voir
     * applyDateAmountOrTextSearch()). Renvoie le pattern LIKE à utiliser pour
     * trier les vraies correspondances de code en premier (null si `q` n'a
     * pas ce format — tri par date inchangé).
     */
    private function applyTransactionSearch($query, string $q, string $timezone): ?string
    {
        $exactCode = TransactionSearchParser::parseOperationCode($q);
        if ($exactCode !== null) {
            $query->whereHas('operation', function ($q2) use ($exactCode) {
                $q2->whereRaw("upper(replace(code, '-', '')) = ?", [$exactCode]);
            });
            return $exactCode;
        }

        if (TransactionSearchParser::isCodeSuffix($q)) {
            $likePattern = '%' . trim($q);
            $query->where(function ($outer) use ($likePattern, $q, $timezone) {
                $outer->whereHas('operation', function ($q2) use ($likePattern) {
                    $q2->whereRaw("upper(code) LIKE ?", [$likePattern]);
                })->orWhere(function ($textGroup) use ($q, $timezone) {
                    $this->applyDateAmountOrTextSearch($textGroup, $q, $timezone);
                });
            });
            return $likePattern;
        }

        $this->applyDateAmountOrTextSearch($query, $q, $timezone);
        return null;
    }

    /**
     * Date reconnue → plage de created_at ; montant reconnu → égalité
     * stricte SUR amount, PLUS les résultats texte (les deux se cumulent,
     * voir la spec) ; sinon, recherche texte multi-mots (TOUS les mots
     * doivent correspondre, chacun dans N'IMPORTE LEQUEL des champs
     * cherchés).
     */
    private function applyDateAmountOrTextSearch($query, string $q, string $timezone): void
    {
        $parsed = TransactionSearchParser::parse($q, $timezone);

        if ($parsed['kind'] === 'day') {
            $query->whereBetween('created_at', [
                $parsed['date']->copy()->startOfDay(),
                $parsed['date']->copy()->endOfDay(),
            ]);
            return;
        }

        if ($parsed['kind'] === 'month') {
            $query->whereBetween('created_at', [$parsed['start'], $parsed['end']]);
            return;
        }

        $this->registerSqliteUnaccentIfNeeded();

        if ($parsed['kind'] === 'amount') {
            $amount = $parsed['amount'];
            $words = preg_split('/\s+/', $parsed['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $query->where(function ($outer) use ($amount, $words) {
                $outer->where('amount', $amount);
                if ($words !== []) {
                    $outer->orWhere(function ($textGroup) use ($words) {
                        foreach ($words as $word) {
                            $this->applySearchWord($textGroup, $word);
                        }
                    });
                }
            });
            return;
        }

        foreach ($parsed['words'] as $word) {
            $this->applySearchWord($query, $word);
        }
    }

    /**
     * Un mot doit correspondre dans AU MOINS UN des champs cherchés — nom de
     * la transaction elle-même (seul champ texte pour une dépense/un
     * apport/un retrait : "Loyer", "Apport de fonds", "Retrait"...), nom de
     * l'article, nom de la variation, note, numéro de facture ou nom du
     * client lié. Insensible à la casse ET aux accents (unaccent() réelle
     * sur PostgreSQL, émulée sur SQLite — voir registerSqliteUnaccentIfNeeded()).
     * % et _ déjà échappés par l'appelant via escapeSearchWord().
     */
    private function applySearchWord($query, string $word): void
    {
        $escaped = $this->escapeSearchWord($word);
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $wrap = fn (string $column) => $isPg
            ? "unaccent({$column}) ILIKE unaccent(?)"
            : "lower(unaccent({$column})) LIKE lower(?)";

        $query->where(function ($q) use ($escaped, $wrap) {
            $q->whereRaw($wrap('transactions.name'), [$escaped])
                ->orWhereRaw($wrap('transactions.note'), [$escaped])
                ->orWhereHas('article', function ($q2) use ($escaped, $wrap) {
                    $q2->whereRaw($wrap('articles.name'), [$escaped]);
                })
                ->orWhereHas('variation', function ($q2) use ($escaped, $wrap) {
                    $q2->whereRaw($wrap('variations.name'), [$escaped]);
                })
                ->orWhereHas('invoice', function ($q2) use ($escaped, $wrap) {
                    $q2->whereRaw($wrap('invoices.invoice_number'), [$escaped])
                        ->orWhereHas('client', function ($q3) use ($escaped, $wrap) {
                            $q3->whereRaw($wrap('clients.name'), [$escaped]);
                        });
                });
        });
    }

    /** Échappe les caractères spéciaux LIKE/ILIKE (% et _) avant de construire le motif `%mot%`. */
    private function escapeSearchWord(string $word): string
    {
        return '%' . addcslashes($word, '\\%_') . '%';
    }

    /**
     * SQLite (tests) n'a pas l'extension `unaccent` de PostgreSQL : on
     * enregistre une fonction PDO équivalente (même table de
     * correspondance que TransactionSearchParser::stripAccents(), casse
     * conservée) pour que EXACTEMENT la même requête SQL tourne sur les
     * deux moteurs. No-op sur PostgreSQL.
     *
     * Pas de cache "déjà enregistrée" : en test, chaque test purge et
     * recrée la connexion SQLite (`DB::purge('sqlite')` dans beforeEach),
     * ce qui donne une toute NOUVELLE instance PDO sans la fonction — un
     * drapeau statique qui survivrait à cette purge bloquerait à tort le
     * ré-enregistrement sur la connexion suivante. sqliteCreateFunction()
     * accepte sans erreur de redéfinir une fonction déjà connue sur LA
     * MÊME instance PDO, donc appeler ceci à chaque recherche est sans
     * risque — et cette méthode ne s'exécute de toute façon jamais en
     * production (driver pgsql uniquement).
     */
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
     * Display the specified transaction.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $transaction = Transaction::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->with(['article' => function ($query) {
                    $query->withSum(['transactions' => function ($q) {
                        $q->where('type', 'sale');
                    }], 'quantity');
                }, 'variation', 'operation'])
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transaction récupérée avec succès',
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $requiredPermission = match ($request->input('type')) {
            'sale' => Permissions::SALES_CREATE,
            'expense' => Permissions::EXPENSES_CREATE,
            'apport', 'retrait' => Permissions::DEPOSITS_MANAGE,
            default => null,
        };
        if ($requiredPermission && Auth::user()->cannot($requiredPermission)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:sale,expense,apport,retrait',
                'article_id' => 'required_if:type,sale|nullable|string|exists:articles,id',
                'variable_id' => 'nullable|string|exists:variations,id',
                'quantity' => 'required_if:type,sale|nullable|integer|min:1',
                'name' => 'required_if:type,expense|nullable|string|max:255',
                'amount' => 'required_if:type,expense|required_if:type,apport|required_if:type,retrait|nullable|numeric|gt:0',
                'sale_price' => 'nullable|numeric|min:0',
                // Apport/retrait uniquement — voir section 2/4 de la
                // spécification. `exists` avec la clause `user_id` embarquée
                // garantit qu'un collaborator_id d'un AUTRE compte échoue en
                // 422 (pas seulement "collaborateur introuvable").
                'funding_source' => 'required_if:type,apport|nullable|in:apport_personnel,collaborateur,pret,autre',
                'collaborator_id' => 'required_if:funding_source,collaborateur|nullable|string|exists:collaborators,id,user_id,' . CurrentAccount::id(),
                'payment_method' => 'required_if:type,apport|required_if:type,retrait|nullable|in:especes,orange_money,moov_money,wave,mtn_money,virement',
                'note' => 'nullable|string|max:2000',
                'date' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validation personnalisée pour variable_id selon le type d'article
            if ($request->type === 'sale' && $request->article_id) {
                $article = Article::find($request->article_id);

                if ($article) {
                    if ($article->type === 'variable' && !$request->variable_id) {
                        // Le frontend à jour envoie toujours variable_id pour un
                        // article variable — n'arriver ici ne peut venir que d'un
                        // client obsolète (onglet resté ouvert depuis avant le déploiement, etc.).
                        return response()->json([
                            'success' => false,
                            'code' => 'STALE_CLIENT_MISSING_VARIATION',
                            'message' => 'Mettez à jour l\'application : choisissez une variation'
                        ], 422);
                    }

                    if ($article->type === 'simple' && $request->variable_id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Pour un article simple, variable_id doit être null'
                        ], 422);
                    }
                }
            }

            $this->rollbackAnyOpenTransaction();

            // Clé d'idempotence optionnelle (en-tête HTTP, générée côté
            // client une fois par soumission et conservée pour tous les
            // retries automatiques de cette même soumission). Absente :
            // comportement strictement inchangé (pas de déduplication),
            // pour rester compatible avec tout client qui ne l'envoie pas.
            $idempotencyKey = $request->header('Idempotency-Key');

            if ($idempotencyKey !== null && !\Illuminate\Support\Str::isUuid($idempotencyKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'En-tête Idempotency-Key invalide (UUID attendu)'
                ], 422);
            }

            if ($idempotencyKey !== null) {
                $existing = $this->findTransactionByIdempotencyKey($idempotencyKey);

                if ($existing) {
                    Log::info('Idempotency-Key déjà traitée : transaction existante renvoyée sans réexécuter les effets métier (stock, notifications)', [
                        'user_id' => CurrentAccount::id(),
                        'idempotency_key' => $idempotencyKey,
                        'existing_transaction_id' => $existing->id,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => $this->successMessageFor($existing->type),
                        'data' => $existing->type === 'sale'
                            ? $this->loadTransactionForResponse($existing)
                            : $existing,
                    ], 201);
                }
            }

            try {
                return DB::transaction(function () use ($request, $idempotencyKey) {
                if ($request->type === 'sale') {
                    // Résout le prix par défaut et préserve le 403 existant
                    // (article/variation introuvable ou d'un autre compte) —
                    // OperationService::create() revérifie de toute façon
                    // l'appartenance au compte ; ce qui n'est PAS dupliqué,
                    // c'est le verrouillage et la vérification de stock,
                    // entièrement délégués au service ci-dessous.
                    $article = Article::where('id', $request->article_id)
                        ->where('user_id', CurrentAccount::id())
                        ->first();

                    if (!$article) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Article non trouvé ou non autorisé'
                        ], 403);
                    }

                    $variation = null;
                    if ($request->variable_id) {
                        $variation = Variation::where('id', $request->variable_id)
                            ->where('article_id', $request->article_id)
                            ->first();

                        if (!$variation) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Variation non trouvée ou non autorisée'
                            ], 403);
                        }
                    }

                    // Gestion du prix de vente — le prix par défaut d'une variation
                    // est SON PROPRE prix, pas celui (min/max) de l'article.
                    $salePrice = $request->sale_price ?? ($variation ? $variation->price : $article->sale_price);

                    // Générer automatiquement le nom de la vente
                    $generatedName = $variation
                        ? "Vente de {$request->quantity} " . $article->name . " " . $variation->name
                        : "Vente de {$request->quantity} " . $article->name;

                    $operation = OperationService::create([
                        'user_id' => CurrentAccount::id(),
                        'type' => 'sale',
                        'idempotency_key' => $idempotencyKey,
                        'lines' => [[
                            'article_id' => $request->article_id,
                            'variation_id' => $request->variable_id,
                            'quantity' => $request->quantity,
                            'unit_price' => $salePrice,
                            'name' => $generatedName,
                            'idempotency_key' => $idempotencyKey,
                        ]],
                    ]);

                    $transaction = $operation->transactions->first();

                    return response()->json([
                        'success' => true,
                        'message' => 'Vente enregistrée avec succès',
                        'data' => $this->loadTransactionForResponse($transaction)
                    ], 201);

                } elseif ($request->type === 'expense') {
                    $operation = OperationService::create([
                        'user_id' => CurrentAccount::id(),
                        'type' => 'expense',
                        'idempotency_key' => $idempotencyKey,
                        'lines' => [[
                            'amount' => $request->amount,
                            'name' => $request->name,
                            'idempotency_key' => $idempotencyKey,
                        ]],
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Dépense enregistrée avec succès',
                        'data' => $operation->transactions->first()
                    ], 201);
                } else { // type === 'apport' ou 'retrait'
                    // Jamais d'article ni de variation sur un apport/retrait
                    // (section 2 de la spécification) — même si le client en
                    // envoyait un par erreur, on l'ignore explicitement ici
                    // plutôt que de le valider puis l'oublier.
                    $isDeposit = $request->type === 'apport';

                    $operation = OperationService::create([
                        'user_id' => CurrentAccount::id(),
                        'type' => $request->type,
                        'idempotency_key' => $idempotencyKey,
                        'payment_method' => $request->payment_method,
                        'lines' => [[
                            'amount' => $request->amount,
                            'name' => $isDeposit ? 'Apport de fonds' : 'Retrait',
                            // "origine" n'a de sens que pour un apport ; un
                            // retrait n'a pas de collaborator_id (il n'est pas
                            // "retiré par" quelqu'un d'autre que le gérant).
                            'funding_source' => $isDeposit ? $request->funding_source : null,
                            'collaborator_id' => ($isDeposit && $request->funding_source === 'collaborateur')
                                ? $request->collaborator_id
                                : null,
                            'note' => $request->note,
                            'idempotency_key' => $idempotencyKey,
                        ]],
                    ]);

                    $transaction = $operation->transactions->first();

                    // Date/heure modifiable (par défaut "maintenant", déjà
                    // posé par les timestamps automatiques de la création
                    // ci-dessus) — contrairement à une vente/dépense, qui ne
                    // propose pas ce champ aujourd'hui.
                    if ($request->filled('date')) {
                        $transaction->created_at = \Illuminate\Support\Carbon::parse($request->date);
                        $transaction->timestamps = false;
                        $transaction->save();
                    }

                    // Pas de ->load('collaborator') ici : Collaborator::wallet
                    // est à la fois une vraie colonne ET un accessor
                    // (getWalletAttribute()) qui lit $this->user en interne —
                    // sérialiser un Collaborator charge donc silencieusement
                    // le User COMPLET (8 accessors "appended", dont plusieurs
                    // avec leur propre requête SQL) dans la réponse. Le
                    // frontend résout déjà le nom d'un collaborateur depuis
                    // sa propre liste (useCollaboratorShares/getCollaborators())
                    // à partir de ce collaborator_id brut, sans avoir besoin
                    // que le backend l'enrichisse ici.
                    return response()->json([
                        'success' => true,
                        'message' => $isDeposit ? 'Apport enregistré avec succès' : 'Retrait enregistré avec succès',
                        'data' => $transaction,
                    ], 201);
                }
                });
            } catch (BasketStockException $e) {
                // Même format de message qu'avant le passage par
                // OperationService — ce chemin (une seule ligne) n'a toujours
                // qu'une seule entrée dans $e->lines.
                $line = $e->lines[0];
                $message = $request->variable_id
                    ? "Stock insuffisant pour {$line['name']} : {$line['available']} disponible(s)"
                    : 'Quantité insuffisante. Quantité disponible: ' . $line['available'];

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 400);
            } catch (BasketValidationException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            } catch (\Illuminate\Database\QueryException $e) {
                // Course entre deux tentatives quasi simultanées d'UNE MÊME
                // soumission (même Idempotency-Key) : la vérification
                // ci-dessus n'avait rien trouvé, mais une autre requête
                // concurrente a créé la ligne entre-temps et notre propre
                // INSERT vient de violer la contrainte unique
                // (user_id, idempotency_key). On ne traite ce cas comme un
                // doublon que si une clé était bien fournie ET que le message
                // confirme explicitement cette contrainte précise — toute
                // autre QueryException (contrainte différente, perte de
                // connexion, etc.) continue de remonter normalement vers le
                // catch générique ci-dessous (réponse 500 inchangée).
                if (!$this->isIdempotencyKeyConflict($e, $idempotencyKey)) {
                    throw $e;
                }

                $existing = $this->findTransactionByIdempotencyKey($idempotencyKey);

                if (!$existing) {
                    // Ne devrait jamais arriver : la violation de contrainte
                    // prouve qu'une ligne avec cette clé existe. Si on ne la
                    // retrouve pas (suppression concurrente improbable), on
                    // laisse remonter au catch générique plutôt que de
                    // renvoyer un faux succès sans données.
                    throw $e;
                }

                Log::warning('Conflit d\'idempotence détecté à l\'écriture (requêtes concurrentes) : transaction existante renvoyée sans réexécuter les effets métier', [
                    'user_id' => CurrentAccount::id(),
                    'idempotency_key' => $idempotencyKey,
                    'existing_transaction_id' => $existing->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $this->successMessageFor($existing->type),
                    'data' => $existing->type === 'sale'
                        ? $this->loadTransactionForResponse($existing)
                        : $existing,
                ], 201);
            }

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la transaction', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified transaction in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $transaction = Transaction::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            // Un apport/retrait ne se modifie pas (pas de besoin exprimé) —
            // seule la suppression est prévue (section 4 de la
            // spécification). Refus explicite plutôt que de tomber dans la
            // branche "dépense" ci-dessous par défaut, qui le traiterait à
            // tort comme telle.
            if (in_array($transaction->type, ['apport', 'retrait'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un apport ou un retrait ne peut pas être modifié, seulement supprimé'
                ], 422);
            }

            // Validation conditionnelle selon le type de transaction
            $validatorRules = [
                'name' => 'required|string|max:255',
            ];

            if ($transaction->type === 'sale') {
                // Pour une vente : on peut modifier sale_price OU quantity (ou les deux)
                $validatorRules['sale_price'] = 'nullable|numeric|min:0';
                $validatorRules['quantity'] = 'nullable|integer|min:1';
            } else {
                // Pour une dépense : on peut modifier name OU amount (ou les deux)
                $validatorRules['amount'] = 'nullable|numeric|min:0';
            }

            $validator = Validator::make($request->all(), $validatorRules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $this->rollbackAnyOpenTransaction();

            return DB::transaction(function () use ($request, $transaction) {
                if ($transaction->type === 'sale') {
                    $article = $transaction->article;

                    if (!$article) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Article associé non trouvé'
                        ], 404);
                    }

                    $oldQuantity = $transaction->quantity;
                    $newQuantity = $request->quantity ?? $transaction->quantity;
                    $newSalePrice = $request->sale_price ?? $transaction->sale_price;

                    // Modèle (A) : article.quantity / variation.quantity sont le
                    // CUMUL stocké en entrée, jamais modifiés par une vente. Le
                    // restant est toujours dérivé de la somme des transactions —
                    // donc AUCUN increment()/decrement() ici (ancien bug : ça
                    // gonflait le cumulé à chaque édition d'une vente). On se
                    // contente de vérifier la disponibilité avant d'enregistrer.
                    if ($newQuantity != $oldQuantity) {
                        if ($transaction->variable_id) {
                            $variation = \App\Models\Variation::where('id', $transaction->variable_id)
                                ->lockForUpdate()
                                ->first();

                            $otherSoldQuantity = Transaction::where('variable_id', $transaction->variable_id)
                                ->where('type', 'sale')
                                ->where('id', '!=', $transaction->id)
                                ->sum('quantity');

                            $availableQuantity = ($variation->quantity ?? 0) - $otherSoldQuantity;

                            if ($newQuantity > $availableQuantity) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "Stock insuffisant pour {$variation->name} : {$availableQuantity} disponible(s)"
                                ], 400);
                            }
                        } else {
                            $otherSoldQuantity = $article->transactions()
                                ->where('type', 'sale')
                                ->where('id', '!=', $transaction->id)
                                ->sum('quantity');

                            $availableQuantity = $article->quantity - $otherSoldQuantity;

                            if ($newQuantity > $availableQuantity) {
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Quantité insuffisante. Quantité disponible: ' . $availableQuantity
                                ], 400);
                            }
                        }
                    }

                    // Calculer le nouveau montant : quantity * sale_price
                    $newAmount = $newQuantity * $newSalePrice;

                    // Mettre à jour la transaction
                    $transaction->update([
                        'name' => $request->name,
                        'quantity' => $newQuantity,
                        'sale_price' => $newSalePrice,
                        'amount' => $newAmount,
                    ]);

                    // Recharger l'article pour avoir les données à jour
                    $article->refresh();
                    $article->load('user.settings');
                    $article->loadSum(['transactions' => function ($q) {
                        $q->where('type', 'sale');
                    }], 'quantity');

                    if ($transaction->variable_id) {
                        $variation = $variation ?? \App\Models\Variation::find($transaction->variable_id);
                        if ($variation) {
                            $variation->refresh();
                            $variation->load('article.user.settings');
                            $variation->loadSum(['transactions' => function ($q) {
                                $q->where('type', 'sale');
                            }], 'quantity');
                            NotificationService::checkLowStockVariation($variation);
                        }
                    } else {
                        // Vérifier si l'article est passé en stock faible et créer une notification
                        NotificationService::checkLowStock($article);
                    }

                } else { // type === 'expense'
                    // Pour une dépense, on peut modifier name et/ou amount
                    $updateData = ['name' => $request->name];

                    if ($request->has('amount')) {
                        $updateData['amount'] = $request->amount;
                    }

                    $transaction->update($updateData);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Transaction modifiée avec succès',
                    'data' => $this->loadTransactionForResponse($transaction)
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Erreur lors de la modification de la transaction', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            // Pas de filtre ->where('user_id', ...) ici : l'appartenance est
            // désormais vérifiée par TransactionPolicy::delete() (centralisé,
            // réutilisable pour restore() et pour les futurs rôles d'équipe).
            $transaction = Transaction::where('id', $id)->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            if (Auth::user()->cannot('delete', $transaction)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé à supprimer cette transaction'
                ], 403);
            }

            // Une ligne d'une opération à PLUSIEURS lignes (panier
            // multi-articles) ne se supprime pas isolément : ça désyncroniserait
            // operation.total_amount et changerait silencieusement ce qu'un
            // reçu déjà émis (code imprimé) est censé représenter. Seule
            // l'opération complète se supprime (DELETE /api/operations/{id}).
            if ($transaction->operation_id) {
                $lineCount = Transaction::where('operation_id', $transaction->operation_id)->count();
                if ($lineCount > 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Supprimez l\'opération complète',
                        'operation_id' => $transaction->operation_id,
                    ], 422);
                }
            }

            // Une vente générée par une facture PAYÉE ne se supprime pas
            // depuis la liste : elle se gère depuis la facture elle-même
            // (annulation/modification), pas par une suppression isolée qui
            // désynchroniserait le total de la facture déjà émise/payée.
            if ($transaction->type === 'sale' && $transaction->invoice_id) {
                $invoice = $transaction->invoice;
                if ($invoice && $invoice->status === 'paid') {
                    return response()->json([
                        'success' => false,
                        'message' => "Cette vente est liée à la facture {$invoice->invoice_number}. Annulez ou modifiez la facture.",
                        'invoice_id' => $invoice->id,
                    ], 422);
                }
            }

            $this->rollbackAnyOpenTransaction();

            return DB::transaction(function () use ($transaction) {
                // Modèle (A) : rien à "restaurer" sur article.quantity/variation.quantity —
                // ce ne sont jamais les ventes qui les modifient. Supprimer cette
                // transaction (soft delete) retire mécaniquement sa quantité de la
                // somme des ventes, ce qui suffit à recalculer le restant
                // correctement (ancien bug : un increment() gonflait le cumulé en
                // plus de ça). Soft delete : la ligne reste en base (deleted_at),
                // restaurable via restore() ci-dessous — les SUM() de stock et le
                // calcul du solde l'excluent déjà automatiquement (SoftDeletes
                // applique son global scope à toute requête Eloquent, y compris
                // les agrégats withSum()/sum() utilisés pour le stock et le wallet).
                $transaction->delete();

                ActivityLogger::log(Auth::id(), 'transaction.deleted', Transaction::class, $transaction->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Transaction supprimée avec succès'
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la transaction', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restaure une transaction supprimée (soft delete) par son propriétaire —
     * utilisé par le bouton « Annuler » du toast de suppression (fenêtre de
     * 5 secondes côté frontend), mais fonctionne indépendamment de cette
     * fenêtre : tant que la ligne n'est pas purgée définitivement, elle est
     * restaurable.
     */
    public function restore(string $id): JsonResponse
    {
        try {
            $transaction = Transaction::withTrashed()->where('id', $id)->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            if (Auth::user()->cannot('restore', $transaction)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé à restaurer cette transaction'
                ], 403);
            }

            $this->rollbackAnyOpenTransaction();

            return DB::transaction(function () use ($transaction) {
                $transaction->restore();

                ActivityLogger::log(Auth::id(), 'transaction.restored', Transaction::class, $transaction->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Transaction restaurée avec succès',
                    'data' => $this->loadTransactionForResponse($transaction),
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Erreur lors de la restauration de la transaction', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la restauration de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete all transactions for the authenticated user
     */
    public function deleteAll(): JsonResponse
    {
        try {
            $userId = CurrentAccount::id();

            // Compter les transactions avant suppression
            $count = Transaction::where('user_id', $userId)->count();

            // Supprimer toutes les transactions de l'utilisateur
            // Les transactions liées aux articles seront gérées par les événements du modèle
            Transaction::where('user_id', $userId)->delete();

            return response()->json([
                'success' => true,
                'message' => "{$count} transaction(s) supprimée(s) avec succès",
                'count' => $count
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression des transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
