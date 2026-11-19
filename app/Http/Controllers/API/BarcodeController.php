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

        // Toujours une chaîne : « 005264 » garde ses zéros.
        $code = Barcode::normalize(rawurldecode($code));
        $error = Barcode::validate($code)['error'];
        if ($error !== null) {
            return response()->json([
                'success' => false,
                'code' => 'barcode_' . $error,
                'message' => __('barcode.' . $error),
                'errors' => ['barcode' => [__('barcode.' . $error)]],
                'barcode' => $code,
            ], 422);
        }

        try {
            $found = $this->barcodes->find(CurrentAccount::id(), $code);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'code' => 'barcode_lookup_failed', 'message' => __('barcode.lookup_failed')], 500);
        }
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
            if ($request->boolean('dry_run')) {
                $count = $this->barcodes->missing($accountId, $articleIds, $variationIds)->count();

                return response()->json([
                    'success' => true,
                    'message' => trans_choice('barcode.generated', $count, ['count' => $count]),
                    'data' => ['count' => $count, 'failed_count' => 0, 'failed' => [], 'dry_run' => true],
                ]);
            }
            $result = $this->barcodes->generateMissing($accountId, $articleIds, $variationIds);
        } catch (BarcodeException $e) {
            return $e->toResponse();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'code' => 'barcode_generate_failed', 'message' => __('barcode.generate_failed')], 500);
        }

        $count = $result['created'];
        $failedCount = count($result['failed']);
        $message = trans_choice('barcode.generated', $count, ['count' => $count]);
        if ($failedCount > 0) {
            $message .= ' ' . trans_choice('barcode.generated_failed', $failedCount, ['count' => $failedCount]);
        }

        return response()->json([
            'success' => $failedCount === 0 || $count > 0,
            'message' => $message,
            'data' => ['count' => $count, 'failed_count' => $failedCount, 'failed' => $result['failed'], 'dry_run' => false],
        ], $count === 0 && $failedCount > 0 ? 422 : 200);
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
