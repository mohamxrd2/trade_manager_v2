<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Transaction;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * TEMPORAIRE — diagnostic ciblé de SQLSTATE[25P02] observé en prod sur
     * store()/update()/destroy(), non reproductible localement malgré
     * plusieurs tentatives (contrôleur direct, serveur HTTP local complet,
     * session forgée). Capture chaque requête SQL exécutée pendant la
     * méthode courante ; loguée UNIQUEMENT en cas d'échec (pas de bruit sur
     * les succès), une petite ligne de log par requête (pas un seul gros
     * bloc — un précédent test a montré qu'un message trop long peut être
     * tronqué/perdu par le pipeline de logs). Objectif : voir la vraie
     * première requête qui casse la transaction, pas seulement celle qui le
     * révèle. À retirer une fois la cause trouvée.
     */
    private array $sqlTrace = [];

    private function startSqlTrace(): void
    {
        $this->sqlTrace = [];
        DB::listen(function ($query) {
            $this->sqlTrace[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
            ];
        });
    }

    private function dumpSqlTraceOnFailure(string $requestId): void
    {
        Log::error("[SQL-TRACE] {$requestId} — " . count($this->sqlTrace) . ' requête(s) exécutée(s) avant l\'échec');
        foreach ($this->sqlTrace as $i => $q) {
            Log::error(sprintf('[SQL-TRACE] %s — %d. [%sms] %s', $requestId, $i + 1, $q['time_ms'], $q['sql']), [
                'bindings' => $q['bindings'],
            ]);
        }
    }

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
        return Transaction::where('user_id', Auth::id())
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
        ]);
        $transaction->article?->makeHidden('user');

        return $transaction;
    }

    /**
     * Display a listing of the transactions for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $withSoldQuantity = function ($query) {
                $query->withSum(['transactions' => function ($q) {
                    $q->where('type', 'sale');
                }], 'quantity');
            };

            $transactions = Transaction::where('user_id', Auth::id())
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
                ])
                ->orderBy('created_at', 'desc')
                ->get();

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
                'data' => $transactions
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
     * Display the specified transaction.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $transaction = Transaction::where('id', $id)
                ->where('user_id', Auth::id())
                ->with(['article' => function ($query) {
                    $query->withSum(['transactions' => function ($q) {
                        $q->where('type', 'sale');
                    }], 'quantity');
                }, 'variation'])
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
        // TEMPORAIRE — vérifie si l'état d'authentification est fiable à
        // l'entrée de cette méthode (piste : 401 intermittent observé sur
        // /api/user juste avant). À retirer une fois la piste confirmée ou
        // écartée.
        Log::info('[AUTH-DIAG] TransactionController::store() entrée', [
            'auth_id' => Auth::id(),
            'auth_check' => Auth::check(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        $sqlTraceRequestId = (string) \Illuminate\Support\Str::uuid();
        $this->startSqlTrace();

        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:sale,expense',
                'article_id' => 'required_if:type,sale|nullable|string|exists:articles,id',
                'variable_id' => 'nullable|string|exists:variations,id',
                'quantity' => 'required_if:type,sale|nullable|integer|min:1',
                'name' => 'required_if:type,expense|nullable|string|max:255',
                'amount' => 'required_if:type,expense|nullable|numeric|min:0',
                'sale_price' => 'nullable|numeric|min:0',
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
                        return response()->json([
                            'success' => false,
                            'message' => 'Pour un article variable, variable_id est obligatoire'
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
                        'user_id' => Auth::id(),
                        'idempotency_key' => $idempotencyKey,
                        'existing_transaction_id' => $existing->id,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => $existing->type === 'sale'
                            ? 'Vente enregistrée avec succès'
                            : 'Dépense enregistrée avec succès',
                        'data' => $existing->type === 'sale'
                            ? $this->loadTransactionForResponse($existing)
                            : $existing,
                    ], 201);
                }
            }

            try {
                return DB::transaction(function () use ($request, $idempotencyKey) {
                $transactionData = [
                    'user_id' => Auth::id(),
                    'type' => $request->type,
                    'idempotency_key' => $idempotencyKey,
                ];

                if ($request->type === 'sale') {
                    // Vérifier que l'article existe et appartient à l'utilisateur
                    // withSum précalcule transactions_sum_quantity : évite que le
                    // ->remaining_quantity plus bas (accessor Article::getSoldQuantityAttribute)
                    // ne déclenche sa propre requête SUM() ad hoc.
                    $article = Article::where('id', $request->article_id)
                        ->where('user_id', Auth::id())
                        ->withSum(['transactions' => function ($q) {
                            $q->where('type', 'sale');
                        }], 'quantity')
                        ->first();

                    if (!$article) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Article non trouvé ou non autorisé'
                        ], 403);
                    }

                    $transactionData['article_id'] = $request->article_id;
                    $transactionData['quantity'] = $request->quantity;

                    // Si c'est une vente de variation
                    if ($request->variable_id) {
                        // Vérifier que la variation existe et appartient à l'article
                        $variation = \App\Models\Variation::where('id', $request->variable_id)
                            ->where('article_id', $request->article_id)
                            ->first();

                        if (!$variation) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Variation non trouvée ou non autorisée'
                            ], 403);
                        }

                        // Vérifier que la quantité disponible dans la variation est suffisante
                        if ($request->quantity > $variation->remaining_quantity) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Quantité insuffisante dans la variation. Quantité disponible: ' . $variation->remaining_quantity
                            ], 400);
                        }

                        $transactionData['variable_id'] = $request->variable_id;
                    } else {
                        // Vente d'article simple
                        // Vérifier que la quantité disponible est suffisante
                        if ($request->quantity > $article->remaining_quantity) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Quantité insuffisante. Quantité disponible: ' . $article->remaining_quantity
                            ], 400);
                        }
                    }

                    // Gestion du prix de vente
                    $salePrice = $request->sale_price ?? $article->sale_price;

                    // Calculer le montant automatiquement
                    $amount = $salePrice * $request->quantity;
                    $transactionData['amount'] = $amount;
                    $transactionData['sale_price'] = $salePrice;

                    // Générer automatiquement le nom de la vente
                    if ($request->variable_id) {
                        // Pour les articles variables, inclure le nom de la variation
                        $generatedName = "Vente de {$request->quantity} " . $article->name . " " . $variation->name;
                    } else {
                        // Pour les articles simples, nom standard
                        $generatedName = "Vente de {$request->quantity} " . $article->name;
                    }
                    $transactionData['name'] = $generatedName;

                    // Créer la transaction
                    $transaction = Transaction::create($transactionData);

                    // Recharger l'article avec les relations nécessaires pour vérifier le stock faible
                    $article->refresh();
                    $article->load('user.settings');
                    $article->loadSum(['transactions' => function ($q) {
                        $q->where('type', 'sale');
                    }], 'quantity');

                    // Vérifier si l'article est passé en stock faible et créer une notification
                    NotificationService::checkLowStock($article);

                    return response()->json([
                        'success' => true,
                        'message' => 'Vente enregistrée avec succès',
                        'data' => $this->loadTransactionForResponse($transaction)
                    ], 201);

                } else { // type === 'expense'
                    $transactionData = array_merge($transactionData, [
                        'article_id' => null,
                        'quantity' => null,
                        'name' => $request->name,
                        'amount' => $request->amount,
                    ]);

                    $transaction = Transaction::create($transactionData);

                    return response()->json([
                        'success' => true,
                        'message' => 'Dépense enregistrée avec succès',
                        'data' => $transaction
                    ], 201);
                }
                });
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
                    'user_id' => Auth::id(),
                    'idempotency_key' => $idempotencyKey,
                    'existing_transaction_id' => $existing->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $existing->type === 'sale'
                        ? 'Vente enregistrée avec succès'
                        : 'Dépense enregistrée avec succès',
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
            $this->dumpSqlTraceOnFailure($sqlTraceRequestId);

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
        $sqlTraceRequestId = (string) \Illuminate\Support\Str::uuid();
        $this->startSqlTrace();

        try {
            $transaction = Transaction::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
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

                    // Si la quantité change, gérer le stock
                    if ($newQuantity != $oldQuantity) {
                        // Restaurer l'ancienne quantité
                        $article->increment('quantity', $oldQuantity);

                        // Calculer la quantité réellement disponible (stock - autres ventes)
                        $otherSoldQuantity = $article->transactions()
                            ->where('type', 'sale')
                            ->where('id', '!=', $transaction->id)
                            ->sum('quantity');

                        $availableQuantity = $article->quantity - $otherSoldQuantity;

                        // Vérifier que la nouvelle quantité est disponible
                        if ($newQuantity > $availableQuantity) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Quantité insuffisante. Quantité disponible: ' . $availableQuantity
                            ], 400);
                        }

                        // Déduire la nouvelle quantité
                        $article->decrement('quantity', $newQuantity);
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

                    // Vérifier si l'article est passé en stock faible et créer une notification
                    NotificationService::checkLowStock($article);

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
            $this->dumpSqlTraceOnFailure($sqlTraceRequestId);

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
        $sqlTraceRequestId = (string) \Illuminate\Support\Str::uuid();
        $this->startSqlTrace();

        try {
            $transaction = Transaction::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            $this->rollbackAnyOpenTransaction();

            return DB::transaction(function () use ($transaction) {
                // Si c'est une vente, restaurer la quantité de l'article
                if ($transaction->type === 'sale' && $transaction->article) {
                    $transaction->article->increment('quantity', $transaction->quantity);
                }

                $transaction->delete();

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
            $this->dumpSqlTraceOnFailure($sqlTraceRequestId);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la transaction',
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
            $userId = Auth::id();

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
