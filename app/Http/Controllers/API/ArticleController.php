<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\StockReplenishment;
use App\Models\Variation;
use App\Services\ArticleStockService;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_VIEW)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $articles = Article::where('user_id', CurrentAccount::id())
                ->withSum(['transactions' => function ($query) {
                    $query->where('type', 'sale');
                }], 'quantity')
                ->with(['variations' => function ($query) {
                    // Charger les variations avec leur quantité vendue
                    $query->withSum('transactions', 'quantity');
                }])
                ->with('user.settings') // Charger les settings pour le calcul de low_stock
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Articles récupérés avec succès',
                'data' => $articles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des articles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $type = $request->input('type');
            $hasVariationsPayload = $request->has('variations');

            $rules = [
                'name' => 'required|string|max:255|unique:articles,name,NULL,id,user_id,' . CurrentAccount::id(),
                'type' => 'required|in:simple,variable',
                'image' => 'nullable|string|max:255',
            ];
            $messages = [
                'name.required' => 'Le nom de l\'article est obligatoire',
                'name.string' => 'Le nom de l\'article doit être une chaîne de caractères',
                'name.max' => 'Le nom de l\'article ne peut pas dépasser 255 caractères',
                'name.unique' => 'Un article avec ce nom existe déjà',
                'type.required' => 'Le type d\'article est obligatoire',
                'type.in' => 'Le type d\'article doit être "simple" ou "variable"',
                'image.string' => 'L\'image doit être une chaîne de caractères',
                'image.max' => 'Le nom de l\'image ne peut pas dépasser 255 caractères',
            ];

            if ($type === 'variable') {
                // Un article variable n'a ni quantité ni prix propres : ils sont
                // calculés depuis ses variations. sale_price/quantity envoyés
                // par un ancien frontend sont simplement ignorés (compatibilité
                // ascendante), jamais exigés ni rejetés.
                if ($hasVariationsPayload) {
                    $rules['variations'] = 'array|min:1';
                    $rules['variations.*.name'] = 'required|string|max:255';
                    $rules['variations.*.quantity'] = 'required|integer|min:0';
                    $rules['variations.*.price'] = 'required|numeric|min:0.01';
                    $rules['variations.*.cost_price'] = 'nullable|numeric|min:0';
                    $messages['variations.min'] = 'Au moins une variation est nécessaire';
                    $messages['variations.*.name.required'] = 'Le nom de la variation est obligatoire';
                    $messages['variations.*.quantity.min'] = 'La quantité doit être supérieure ou égale à 0';
                    $messages['variations.*.price.required'] = 'Le prix de la variation est obligatoire';
                    $messages['variations.*.price.min'] = 'Le prix de la variation doit être supérieur à 0';
                }
            } else {
                $rules['sale_price'] = 'required|numeric|min:0';
                $rules['quantity'] = 'required|integer|min:0';
                $messages['sale_price.required'] = 'Le prix de vente est obligatoire';
                $messages['sale_price.numeric'] = 'Le prix de vente doit être un nombre';
                $messages['sale_price.min'] = 'Le prix de vente doit être supérieur ou égal à 0';
                $messages['quantity.required'] = 'La quantité est obligatoire';
                $messages['quantity.integer'] = 'La quantité doit être un nombre entier';
                $messages['quantity.min'] = 'La quantité doit être supérieure ou égale à 0';
            }

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($type === 'variable' && $hasVariationsPayload) {
                $seenNames = [];
                foreach ($request->input('variations') as $variationData) {
                    $normalized = mb_strtolower(trim($variationData['name']));
                    if (isset($seenNames[$normalized])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Erreur de validation',
                            'errors' => ['variations' => ["Nom de variation en double : \"{$variationData['name']}\""]]
                        ], 422);
                    }
                    $seenNames[$normalized] = true;
                }
            }

            $article = DB::transaction(function () use ($request, $type, $hasVariationsPayload) {
                $article = Article::create([
                    'user_id' => CurrentAccount::id(),
                    'name' => $request->name,
                    'type' => $type,
                    'image' => $request->image,
                    'sale_price' => $type === 'variable' ? 0 : $request->sale_price,
                    'quantity' => $type === 'variable' ? 0 : $request->quantity,
                ]);

                if ($type === 'variable' && $hasVariationsPayload) {
                    foreach ($request->input('variations') as $variationData) {
                        Variation::create([
                            'article_id' => $article->id,
                            'name' => trim($variationData['name']),
                            'quantity' => $variationData['quantity'],
                            'price' => $variationData['price'],
                            'cost_price' => $variationData['cost_price'] ?? null,
                        ]);
                    }

                    // L'observer a déjà resynchronisé après chaque création ;
                    // appel explicite final pour un état garanti cohérent.
                    ArticleStockService::syncVariableTotals($article);
                }

                return $article;
            });

            // Charger les relations nécessaires pour les accessors
            $article->load('user.settings');

            if ($article->type === 'variable') {
                $article->load(['variations' => function ($query) {
                    $query->withSum('transactions', 'quantity');
                }]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Article créé avec succès',
                'data' => $article
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // Contrainte unique (article_id, lower(trim(name))) sur variations —
            // filet de sécurité si la pré-vérification applicative a laissé
            // passer un doublon (ex. appel concurrent).
            if (str_contains($e->getMessage(), 'variations_article_id_norm_name_unique')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => ['variations' => ['Deux variations ne peuvent pas porter le même nom']]
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'article',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'article',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_VIEW)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $article = Article::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->withSum(['transactions' => function ($query) {
                    $query->where('type', 'sale');
                }], 'quantity')
                ->with('user.settings') // Charger les settings pour le calcul de low_stock
                ->with(['variations' => function ($query) {
                    $query->withSum('transactions', 'quantity');
                }])
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Article récupéré avec succès',
                'data' => $article
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'article',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $article = Article::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->with('user.settings') // Charger les settings pour le calcul de low_stock
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé'
                ], 404);
            }

            // Vérifier si l'utilisateur essaie de modifier le type
            if ($request->has('type') && $request->type !== $article->type) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le type d\'un article ne peut pas être modifié'
                ], 400);
            }

            $rules = [
                'name' => 'required|string|max:255|unique:articles,name,' . $id . ',id,user_id,' . CurrentAccount::id(),
                'image' => 'nullable|string|max:255',
            ];
            $messages = [
                'name.required' => 'Le nom de l\'article est obligatoire',
                'name.string' => 'Le nom de l\'article doit être une chaîne de caractères',
                'name.max' => 'Le nom de l\'article ne peut pas dépasser 255 caractères',
                'name.unique' => 'Un article avec ce nom existe déjà',
                'image.string' => 'L\'image doit être une chaîne de caractères',
                'image.max' => 'Le nom de l\'image ne peut pas dépasser 255 caractères',
            ];

            // sale_price/quantity ne sont saisissables que pour un article simple —
            // pour un article variable, ces colonnes sont un cache synchronisé
            // depuis les variations (ArticleStockService::syncVariableTotals).
            if ($article->type === 'simple') {
                $rules['sale_price'] = 'required|numeric|min:0';
                $rules['quantity'] = 'required|integer|min:0';
                $messages['sale_price.required'] = 'Le prix de vente est obligatoire';
                $messages['sale_price.numeric'] = 'Le prix de vente doit être un nombre';
                $messages['sale_price.min'] = 'Le prix de vente doit être supérieur ou égal à 0';
                $messages['quantity.required'] = 'La quantité est obligatoire';
                $messages['quantity.integer'] = 'La quantité doit être un nombre entier';
                $messages['quantity.min'] = 'La quantité doit être supérieure ou égale à 0';
            }

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = [
                'name' => $request->name,
                'image' => $request->image,
            ];

            if ($article->type === 'simple') {
                $updateData['sale_price'] = $request->sale_price;
                $updateData['quantity'] = $request->quantity;
            }

            $article->update($updateData);

            // Charger les relations nécessaires pour les accessors
            $article->load('user.settings');
            
            // Charger les variations si l'article est de type variable
            if ($article->type === 'variable') {
                $article->load(['variations' => function ($query) {
                    $query->withSum('transactions', 'quantity');
                }]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Article modifié avec succès',
                'data' => $article
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l\'article',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $article = Article::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->with('user.settings') // Charger les settings pour le calcul de low_stock
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé'
                ], 404);
            }

            $article->delete();

            return response()->json([
                'success' => true,
                'message' => 'Article supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'article',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete all articles for the authenticated user
     */
    public function deleteAll(): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $userId = CurrentAccount::id();

            // Compter les articles avant suppression
            $count = Article::where('user_id', $userId)->count();

            // Supprimer tous les articles de l'utilisateur
            // Les variations et transactions liées seront gérées par les contraintes de clé étrangère
            Article::where('user_id', $userId)->delete();

            return response()->json([
                'success' => true,
                'message' => "{$count} produit(s) supprimé(s) avec succès",
                'count' => $count
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression des produits',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add stock to an article (replenishment).
     * 
     * POST /api/articles/{id}/add-stock
     * Body: { "quantity": 20, "note": "Réapprovisionnement fournisseur" }
     */
    public function addStock(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_RESTOCK)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            // Récupérer l'article appartenant à l'utilisateur connecté
            $article = Article::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé'
                ], 404);
            }

            $rules = [
                'quantity' => 'required|integer|min:1',
                'note' => 'nullable|string|max:500',
            ];
            $messages = [
                'quantity.required' => 'La quantité à ajouter est obligatoire',
                'quantity.integer' => 'La quantité doit être un nombre entier',
                'quantity.min' => 'La quantité doit être au minimum de 1',
                'note.string' => 'La note doit être une chaîne de caractères',
                'note.max' => 'La note ne peut pas dépasser 500 caractères',
            ];

            // Un article variable n'a pas de stock propre : le réapprovisionnement
            // cible toujours une variation précise. Un client à jour envoie
            // toujours variation_id ici — ce cas ne peut venir que d'un client
            // obsolète. Vérifié à part (plutôt que via le Validator générique
            // ci-dessous) pour renvoyer un message ET un code au niveau racine
            // de la réponse, exploitables simplement par le frontend.
            if ($article->type === 'variable' && !$request->filled('variation_id')) {
                return response()->json([
                    'success' => false,
                    'code' => 'STALE_CLIENT_MISSING_VARIATION',
                    'message' => 'Mettez à jour l\'application : choisissez une variation',
                ], 422);
            }

            if ($article->type === 'variable') {
                $rules['variation_id'] = 'required|string|exists:variations,id';
                $messages['variation_id.exists'] = 'La variation n\'existe pas';
            }

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $variation = null;

            if ($article->type === 'variable') {
                $variation = Variation::where('id', $request->variation_id)
                    ->where('article_id', $article->id)
                    ->first();

                if (!$variation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Variation non trouvée ou non autorisée'
                    ], 403);
                }
            }

            $quantityToAdd = (int) $request->quantity;

            // Utiliser une transaction DB pour garantir l'intégrité
            DB::transaction(function () use ($article, $variation, $quantityToAdd, $request) {
                if ($variation) {
                    // L'observer de Variation resynchronise automatiquement l'article.
                    $variation->increment('quantity', $quantityToAdd);
                } else {
                    $article->increment('quantity', $quantityToAdd);
                }

                StockReplenishment::create([
                    'user_id' => CurrentAccount::id(),
                    'created_by_user_id' => CurrentAccount::actorId(),
                    'article_id' => $article->id,
                    'variation_id' => $variation?->id,
                    'quantity_added' => $quantityToAdd,
                    'note' => $request->note,
                ]);
            });

            // Recharger l'article avec les relations pour la réponse
            $article->refresh();
            $article->load('user.settings');

            if ($article->type === 'variable') {
                $article->load(['variations' => function ($query) {
                    $query->withSum('transactions', 'quantity');
                }]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock ajouté avec succès',
                'data' => [
                    'article' => $article,
                    'variation' => $variation?->fresh(),
                    'added_quantity' => $quantityToAdd,
                    'new_quantity' => $variation ? $variation->fresh()->quantity : $article->quantity,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout du stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock replenishment history for an article.
     * 
     * GET /api/articles/{id}/stock-history
     * Query params: per_page (default: 20), page (default: 1)
     */
    public function stockHistory(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_VIEW)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            // Vérifier que l'article existe et appartient à l'utilisateur
            $article = Article::where('id', $id)
                ->where('user_id', CurrentAccount::id())
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé'
                ], 404);
            }

            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            // Récupérer l'historique des réapprovisionnements
            $history = StockReplenishment::where('article_id', $id)
                ->with(['user:id,first_name,last_name,username', 'variation:id,name'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Calculer le total des réapprovisionnements
            $totalReplenished = StockReplenishment::where('article_id', $id)
                ->sum('quantity_added');

            return response()->json([
                'success' => true,
                'message' => 'Historique des réapprovisionnements récupéré avec succès',
                'data' => [
                    'article' => [
                        'id' => $article->id,
                        'name' => $article->name,
                        'current_quantity' => $article->quantity,
                    ],
                    'history' => $history->items(),
                    'summary' => [
                        'total_replenished' => (int) $totalReplenished,
                        'replenishment_count' => $history->total(),
                    ],
                    'pagination' => [
                        'current_page' => $history->currentPage(),
                        'per_page' => $history->perPage(),
                        'total' => $history->total(),
                        'last_page' => $history->lastPage(),
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
