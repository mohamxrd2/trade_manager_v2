<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $articles = Article::where('user_id', Auth::id())
                ->withSum(['transactions' => function ($query) {
                    $query->where('type', 'sale');
                }], 'quantity')
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
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'sale_price' => 'required|numeric|min:0',
                'quantity' => 'required|integer|min:0',
                'type' => 'required|in:simple,variable',
                'image' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $article = Article::create([
                'user_id' => Auth::id(),
                'name' => $request->name,
                'sale_price' => $request->sale_price,
                'quantity' => $request->quantity,
                'type' => $request->type,
                'image' => $request->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Article créé avec succès',
                'data' => $article
            ], 201);
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
        try {
            $article = Article::where('id', $id)
                ->where('user_id', Auth::id())
                ->withSum(['transactions' => function ($query) {
                    $query->where('type', 'sale');
                }], 'quantity')
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
        try {
            $article = Article::where('id', $id)
                ->where('user_id', Auth::id())
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

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'sale_price' => 'required|numeric|min:0',
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

            $article->update([
                'name' => $request->name,
                'sale_price' => $request->sale_price,
                'quantity' => $request->quantity,
                'image' => $request->image,
            ]);

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
        try {
            $article = Article::where('id', $id)
                ->where('user_id', Auth::id())
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
}
