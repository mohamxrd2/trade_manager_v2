<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Variation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class VariationController extends Controller
{
    /**
     * Display a listing of the variations for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            // Récupérer toutes les variations des articles de l'utilisateur connecté
            $variations = Variation::whereHas('article', function ($query) {
                $query->where('user_id', Auth::id());
            })
            ->withSum('transactions', 'quantity')
            ->with([
                'article' => function ($query) {
                    $query->withSum('transactions', 'quantity')
                          ->select('id', 'name', 'type', 'sale_price', 'quantity', 'image');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Variations récupérées avec succès',
                'data' => $variations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des variations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified variation.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $variation = Variation::whereHas('article', function ($query) {
                $query->where('user_id', Auth::id());
            })
            ->withSum('transactions', 'quantity')
            ->with([
                'article' => function ($query) {
                    $query->withSum('transactions', 'quantity')
                          ->select('id', 'name', 'type', 'sale_price', 'quantity', 'image');
                }
            ])
            ->find($id);

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variation non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Variation récupérée avec succès',
                'data' => $variation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la variation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created variation.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'article_id' => 'required|string|exists:articles,id',
                'name' => 'required|string|max:255',
                'quantity' => 'required|integer|min:0',
                'image' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

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

            // Vérifier que l'article est de type 'variable'
            if ($article->type !== 'variable') {
                return response()->json([
                    'success' => false,
                    'message' => 'Les variations ne peuvent être ajoutées qu\'aux articles de type "variable"'
                ], 400);
            }

            // Vérifier si une variation avec le même nom existe déjà pour cet article
            $existingVariation = Variation::where('article_id', $request->article_id)
                ->where('name', $request->name)
                ->first();

            if ($existingVariation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une variation avec ce nom existe déjà pour cet article'
                ], 400);
            }

            // Vérifier que la quantité de la variation est positive
            if ($request->quantity <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité de la variation doit être positive'
                ], 400);
            }

            // Calculer la somme des quantités des variations existantes pour cet article
            $totalVariationsQuantity = Variation::where('article_id', $request->article_id)
                ->sum('quantity');

            // Vérifier que la somme des quantités des variations (existantes + nouvelle) ne dépasse pas la quantité totale de l'article
            if ($totalVariationsQuantity + $request->quantity > $article->quantity) {
                $availableQuantity = $article->quantity - $totalVariationsQuantity;
                return response()->json([
                    'success' => false,
                    'message' => 'La somme des quantités des variations ne peut pas dépasser la quantité totale de l\'article. Quantité disponible pour les variations: ' . $availableQuantity
                ], 400);
            }

            $variation = Variation::create([
                'article_id' => $request->article_id,
                'name' => $request->name,
                'quantity' => $request->quantity,
                'image' => $request->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Variation ajoutée avec succès',
                'data' => $variation
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la variation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified variation.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $variation = Variation::find($id);

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variation non trouvée'
                ], 404);
            }

            // Vérifier que l'article parent appartient à l'utilisateur
            $article = Article::where('id', $variation->article_id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé ou non autorisé'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'quantity' => 'required|integer|min:0',
                'image' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier si une autre variation avec le même nom existe déjà pour cet article
            $existingVariation = Variation::where('article_id', $variation->article_id)
                ->where('name', $request->name)
                ->where('id', '!=', $id)
                ->first();

            if ($existingVariation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une variation avec ce nom existe déjà pour cet article'
                ], 400);
            }

            // Vérifier que la quantité de la variation est positive
            if ($request->quantity <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité de la variation doit être positive'
                ], 400);
            }

            // Calculer la somme des quantités des autres variations (excluant celle en cours de modification)
            $totalOtherVariationsQuantity = Variation::where('article_id', $variation->article_id)
                ->where('id', '!=', $id)
                ->sum('quantity');

            // Vérifier que la somme des quantités des variations (autres + modifiée) ne dépasse pas la quantité totale de l'article
            if ($totalOtherVariationsQuantity + $request->quantity > $article->quantity) {
                $availableQuantity = $article->quantity - $totalOtherVariationsQuantity;
                return response()->json([
                    'success' => false,
                    'message' => 'La somme des quantités des variations ne peut pas dépasser la quantité totale de l\'article. Quantité disponible pour cette variation: ' . $availableQuantity
                ], 400);
            }

            $variation->update([
                'name' => $request->name,
                'quantity' => $request->quantity,
                'image' => $request->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Variation modifiée avec succès',
                'data' => $variation
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de la variation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified variation.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $variation = Variation::find($id);

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variation non trouvée'
                ], 404);
            }

            // Vérifier que l'article parent appartient à l'utilisateur
            $article = Article::where('id', $variation->article_id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article non trouvé ou non autorisé'
                ], 403);
            }

            $variation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Variation supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la variation',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}