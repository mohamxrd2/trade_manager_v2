<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\InvoiceItem;
use App\Models\Transaction;
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
                          ->with('user.settings') // Charger les settings pour le calcul de low_stock
                          ->select('id', 'name', 'type', 'sale_price', 'quantity', 'image', 'user_id');
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
                          ->with('user.settings') // Charger les settings pour le calcul de low_stock
                          ->select('id', 'name', 'type', 'sale_price', 'quantity', 'image', 'user_id');
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
                'price' => 'required|numeric|min:0.01',
                'cost_price' => 'nullable|numeric|min:0',
                'image' => 'nullable|string|max:255',
            ],[
                'article_id.required' => 'L\'article est obligatoire',
                'article_id.string' => 'L\'article doit être une chaîne de caractères',
                'article_id.exists' => 'L\'article n\'existe pas',
                'name.required' => 'Le nom de la variation est obligatoire',
                'name.string' => 'Le nom de la variation doit être une chaîne de caractères',
                'name.max' => 'Le nom de la variation ne peut pas dépasser 255 caractères',
                'quantity.required' => 'La quantité est obligatoire',
                'quantity.integer' => 'La quantité doit être un nombre entier',
                'quantity.min' => 'La quantité doit être supérieure ou égale à 0',
                'price.required' => 'Le prix de la variation est obligatoire',
                'price.numeric' => 'Le prix doit être un nombre',
                'price.min' => 'Le prix doit être supérieur à 0',
                'image.string' => 'L\'image doit être une chaîne de caractères',
                'image.max' => 'Le nom de l\'image ne peut pas dépasser 255 caractères',
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

            // Vérifier si une variation avec le même nom (insensible à la casse et aux
            // espaces) existe déjà pour cet article — reflète l'index unique partiel
            // (article_id, lower(trim(name))) WHERE deleted_at IS NULL.
            $normalizedName = mb_strtolower(trim($request->name));
            $existingVariation = Variation::where('article_id', $request->article_id)
                ->whereRaw('lower(trim(name)) = ?', [$normalizedName])
                ->first();

            if ($existingVariation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une variation avec ce nom existe déjà pour cet article'
                ], 400);
            }

            // Pas de plafond lié à article.quantity : une variation peut être
            // ajoutée à tout moment, sans limite liée à une "quantité initiale"
            // de l'article (ce plafond est supprimé — l'article.quantity est
            // désormais un cache calculé, pas une borne).
            $variation = Variation::create([
                'article_id' => $request->article_id,
                'name' => trim($request->name),
                'quantity' => $request->quantity,
                'price' => $request->price,
                'cost_price' => $request->cost_price,
                'image' => $request->image,
            ]);

            // Charger les relations nécessaires pour les accessors
            $variation->load('article.user.settings');

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
                'price' => 'required|numeric|min:0.01',
                'cost_price' => 'nullable|numeric|min:0',
                'image' => 'nullable|string|max:255',
            ], [
                'price.required' => 'Le prix de la variation est obligatoire',
                'price.numeric' => 'Le prix doit être un nombre',
                'price.min' => 'Le prix doit être supérieur à 0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier si une autre variation avec le même nom (insensible à la casse
            // et aux espaces) existe déjà pour cet article
            $normalizedName = mb_strtolower(trim($request->name));
            $existingVariation = Variation::where('article_id', $variation->article_id)
                ->whereRaw('lower(trim(name)) = ?', [$normalizedName])
                ->where('id', '!=', $id)
                ->first();

            if ($existingVariation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une variation avec ce nom existe déjà pour cet article'
                ], 400);
            }

            // Pas de plafond lié à article.quantity (supprimé).
            $variation->update([
                'name' => trim($request->name),
                'quantity' => $request->quantity,
                'price' => $request->price,
                'cost_price' => $request->cost_price,
                'image' => $request->image,
            ]);

            // Charger les relations nécessaires pour les accessors
            $variation->load('article.user.settings');

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
    public function destroy(Request $request, string $id): JsonResponse
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

            // Interdit de supprimer la dernière variation active d'un article
            // variable — un article variable doit toujours avoir au moins une
            // variation pour porter son stock/prix.
            $activeVariationsCount = Variation::where('article_id', $article->id)->count();
            if ($activeVariationsCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer la dernière variation de cet article'
                ], 422);
            }

            // Un stock restant ne peut pas disparaître silencieusement : il faut
            // une confirmation explicite (le frontend l'affiche comme une boîte
            // de dialogue nommant la quantité et sa valeur).
            $remaining = $variation->remaining_quantity;
            $confirmed = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN);

            if ($remaining > 0 && !$confirmed) {
                return response()->json([
                    'success' => false,
                    'message' => "Cette variation contient encore {$remaining} unité(s). Videz le stock ou confirmez l'archivage.",
                    'remaining_quantity' => $remaining,
                ], 422);
            }

            $hasHistory = Transaction::where('variable_id', $variation->id)->exists()
                || InvoiceItem::where('variation_id', $variation->id)->exists();

            if ($hasHistory) {
                // Historique de ventes/factures à préserver : archive seulement.
                $variation->delete();
                $message = 'Variation archivée avec succès';
            } else {
                // Rien ne la référence : suppression réelle, pas d'historique à perdre.
                $variation->forceDelete();
                $message = 'Variation supprimée avec succès';
            }

            // La suppression déclenche aussi l'observer (resync de l'article) ;
            // pour un forceDelete, Eloquent déclenche toujours l'évènement
            // 'deleted' avant de retirer la ligne.
            return response()->json([
                'success' => true,
                'message' => $message
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