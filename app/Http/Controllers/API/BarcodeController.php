<?php

namespace App\Http\Controllers\API;

use App\Exceptions\BarcodeException;
use App\Http\Controllers\Concerns\UsesRequestLocale;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Variation;
use App\Services\BarcodeService;
use App\Support\Barcode;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Codes-barres : recherche par code (vente, stock), génération d'un code
 * interne pour un article simple ou une variation, génération en lot.
 * Rechercher = qui peut vendre ou voir le stock (caissier compris) ;
 * générer = qui peut modifier le stock (stock.edit). Tout est limité au
 * compte courant.
 */
class BarcodeController extends Controller
{
    use UsesRequestLocale;

    public function __construct(private BarcodeService $barcodes)
    {
    }

    /** GET /api/barcodes/{code} — l'article (et la variation) qui porte ce code. */
    public function show(Request $request, string $code): JsonResponse
    {
        $user = Auth::user();
        if ($user->cannot(Permissions::SALES_CREATE) && $user->cannot(Permissions::STOCK_VIEW)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }
        $this->useRequestLocale($request);

        $code = Barcode::normalize(rawurldecode($code));
        $found = $code === '' ? null : $this->barcodes->find(CurrentAccount::id(), $code);
        if (!$found) {
            return response()->json([
                'success' => false,
                'code' => 'barcode_not_found',
                'message' => __('barcode.not_found'),
                'barcode' => $code,
            ], 404);
        }

        // Même forme que GET /api/articles (stock restant, prix, image, variations).
        $article = Article::whereKey($found['article']->id)
            ->withSum(['transactions' => fn ($query) => $query->where('type', 'sale')], 'quantity')
            ->with(['variations' => fn ($query) => $query->withSum('transactions', 'quantity')])
            ->with('user.settings')
            ->first()
            ->makeHidden('user');
        $variation = $found['variation']
            ? $article->variations->firstWhere('id', $found['variation']->id)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'barcode' => $code,
                'article' => $article,
                'variation' => $variation,
            ],
        ]);
    }

    /** POST /api/articles/{id}/barcode — code interne pour un article simple sans code. */
    public function generateForArticle(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }
        $this->useRequestLocale($request);

        $article = Article::where('id', $id)->where('user_id', CurrentAccount::id())->first();
        if (!$article) {
            return response()->json(['success' => false, 'message' => 'Article non trouvé'], 404);
        }

        return $this->generate($article);
    }

    /** POST /api/variations/{id}/barcode — code interne pour une variation sans code. */
    public function generateForVariation(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }
        $this->useRequestLocale($request);

        $variation = Variation::whereKey($id)
            ->whereHas('article', fn ($query) => $query->where('user_id', CurrentAccount::id()))
            ->first();
        if (!$variation) {
            return response()->json(['success' => false, 'message' => 'Variation non trouvée'], 404);
        }

        return $this->generate($variation);
    }

    /**
     * POST /api/barcodes/generate-missing — codes des articles simples et
     * variations sans code : tous, ou article_ids / variation_ids.
     * dry_run=true : renvoie seulement le nombre qui serait créé (confirmation).
     */
    public function generateMissing(Request $request): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::STOCK_EDIT)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }
        $this->useRequestLocale($request);

        $validator = Validator::make($request->all(), [
            'article_ids' => 'nullable|array|max:1000',
            'article_ids.*' => 'string|max:64',
            'variation_ids' => 'nullable|array|max:1000',
            'variation_ids.*' => 'string|max:64',
            'dry_run' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $validator->errors()], 422);
        }

        $accountId = CurrentAccount::id();
        $articleIds = $request->has('article_ids') ? $request->input('article_ids', []) : null;
        $variationIds = $request->has('variation_ids') ? $request->input('variation_ids', []) : null;

        try {
            $count = $request->boolean('dry_run')
                ? $this->barcodes->missing($accountId, $articleIds, $variationIds)->count()
                : $this->barcodes->generateMissing($accountId, $articleIds, $variationIds);
        } catch (BarcodeException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'message' => trans_choice('barcode.generated', $count, ['count' => $count]),
            'data' => ['count' => $count, 'dry_run' => $request->boolean('dry_run')],
        ]);
    }

    private function generate(Article|Variation $item): JsonResponse
    {
        try {
            $code = $this->barcodes->generateFor(CurrentAccount::id(), $item);
        } catch (BarcodeException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'message' => trans_choice('barcode.generated', 1, ['count' => 1]),
            'data' => ['barcode' => $code, 'id' => $item->id],
        ], 201);
    }
}
