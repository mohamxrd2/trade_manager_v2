<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Display a listing of the transactions for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $transactions = Transaction::where('user_id', Auth::id())
                ->with(['article' => function ($query) {
                    $query->withSum(['transactions' => function ($q) {
                        $q->where('type', 'sale');
                    }], 'quantity');
                }, 'variation'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Transactions récupérées avec succès',
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
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

            return DB::transaction(function () use ($request) {
                $transactionData = [
                    'user_id' => Auth::id(),
                    'type' => $request->type,
                ];

                if ($request->type === 'sale') {
                    // Vérifier que l'article existe et appartient à l'utilisateur
                    $article = Article::where('id', $request->article_id)
                        ->where('user_id', Auth::id())
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

                    return response()->json([
                        'success' => true,
                        'message' => 'Vente enregistrée avec succès',
                        'data' => $transaction->load(['article' => function ($query) {
                            $query->withSum(['transactions' => function ($q) {
                                $q->where('type', 'sale');
                            }], 'quantity');
                        }, 'variation'])
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

        } catch (\Exception $e) {
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
                ->where('user_id', Auth::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'quantity' => 'required_if:type,sale|nullable|integer|min:1',
                'amount' => 'required_if:type,expense|nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            return DB::transaction(function () use ($request, $transaction) {
                $oldQuantity = $transaction->quantity;
                $newQuantity = $request->quantity;

                if ($transaction->type === 'sale') {
                    $article = $transaction->article;
                    
                    if (!$article) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Article associé non trouvé'
                        ], 404);
                    }

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

                    // Calculer le nouveau montant
                    $newAmount = $article->sale_price * $newQuantity;

                    // Mettre à jour la transaction
                    $transaction->update([
                        'name' => $request->name,
                        'quantity' => $newQuantity,
                        'amount' => $newAmount,
                    ]);

                    // Déduire la nouvelle quantité
                    $article->decrement('quantity', $newQuantity);

                } else { // type === 'expense'
                    $transaction->update([
                        'name' => $request->name,
                        'amount' => $request->amount,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Transaction modifiée avec succès',
                    'data' => $transaction->load(['article' => function ($query) {
                        $query->withSum(['transactions' => function ($q) {
                            $q->where('type', 'sale');
                        }], 'quantity');
                    }, 'variation'])
                ]);
            });

        } catch (\Exception $e) {
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
            $transaction = Transaction::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction non trouvée'
                ], 404);
            }

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
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
