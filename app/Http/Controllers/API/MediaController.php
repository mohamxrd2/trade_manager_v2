<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Media;
use App\Models\User;
use App\Models\Variation;
use App\Services\MediaService;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * POST   /api/media                     { file, kind, owner_id? } — envoie (et rattache) une image
 * DELETE /api/media/{kind}/{ownerId}    — retire l'image d'un élément ("me" = sa propre photo)
 *
 * kind : product (article), variation, logo (entreprise), avatar (sa photo,
 * ou celle d'un membre de l'équipe). Sans owner_id, une photo d'article ou
 * de variation est enregistrée sans rattachement : l'écran « Nouvel article »
 * la passe ensuite dans image_media_id à la création (voir ArticleController).
 */
class MediaController extends Controller
{
    public function __construct(private MediaService $media)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $maxKb = (int) config('media.max_upload_kb');
        $request->validate([
            'file' => ['required', 'file', 'max:' . $maxKb],
            'kind' => ['required', Rule::in(Media::KINDS)],
            'owner_id' => ['nullable', 'string', 'max:64'],
        ], [
            'file.max' => __('media.too_large', ['max' => round($maxKb / 1024)]),
            'file.uploaded' => __('media.too_large', ['max' => round($maxKb / 1024)]),
        ]);

        $kind = $request->input('kind');
        $ownerId = $request->input('owner_id');
        $accountId = CurrentAccount::id();
        $this->media->pruneOrphans($accountId);

        // Cibles résolues AVANT l'envoi : un refus ne laisse aucun fichier.
        $resolved = $ownerId || in_array($kind, ['logo', 'avatar'], true)
            ? $this->resolveTargets($kind, $ownerId, $accountId)
            : $this->authorizeUnattached($kind);

        $media = $this->media->upload($request->file('file'), $kind, $accountId, Auth::id());

        if ($resolved) {
            [$ownerType, $ownerKey, $targets] = $resolved;
            $this->media->attach($media, $ownerType, $ownerKey, $targets);
        }

        return response()->json(['success' => true, 'data' => $media->fresh()], 201);
    }

    public function destroy(Request $request, string $kind, string $ownerId): JsonResponse
    {
        $this->useRequestLocale($request);
        abort_unless(in_array($kind, Media::KINDS, true), 404);

        [, , $targets] = $this->resolveTargets($kind, $ownerId === 'me' ? null : $ownerId, CurrentAccount::id());
        $this->media->detach($targets);

        return response()->json(['success' => true]);
    }

    /** Photo d'un article pas encore créé : seul le droit de modifier le stock compte. */
    private function authorizeUnattached(string $kind): ?array
    {
        abort_unless(in_array($kind, ['product', 'variation'], true), 422);
        $this->authorizePermission(Permissions::STOCK_EDIT);

        return null;
    }

    /**
     * Élément visé + droit de le modifier, toujours dans le compte courant.
     *
     * @return array{0: string, 1: string, 2: array<int, array{0: \Illuminate\Database\Eloquent\Model, 1: string, 2: string}>}
     */
    private function resolveTargets(string $kind, ?string $ownerId, string $accountId): array
    {
        switch ($kind) {
            case 'product':
                $this->authorizePermission(Permissions::STOCK_EDIT);
                $article = Article::where('id', $ownerId)->where('user_id', $accountId)->first();
                abort_unless($article, 404, __('media.not_found'));

                return ['article', $article->id, [[$article, 'image_media_id', 'image']]];

            case 'variation':
                $this->authorizePermission(Permissions::STOCK_EDIT);
                $variation = Variation::where('id', $ownerId)
                    ->whereHas('article', fn ($q) => $q->where('user_id', $accountId))
                    ->first();
                abort_unless($variation, 404, __('media.not_found'));

                return ['variation', $variation->id, [[$variation, 'image_media_id', 'image']]];

            case 'logo':
                $this->authorizePermission(Permissions::SETTINGS_MANAGE);
                $company = Company::where('user_id', $accountId)->first();
                abort_unless($company, 404, __('media.not_found'));

                return ['company', $company->id, [[$company, 'logo_media_id', 'logo']]];

            case 'avatar':
            default:
                $me = Auth::user();
                if (!$ownerId || $ownerId === $me->id) {
                    return ['user', $me->id, [[$me, 'avatar_media_id', 'profile_image']]];
                }

                return $this->resolveMember($ownerId, $accountId);
        }
    }

    /**
     * Membre de l'équipe : son compte d'accès ET sa fiche associé (la même
     * personne, voir TeamController::resolvePerson), photo synchronisée.
     */
    private function resolveMember(string $id, string $accountId): array
    {
        $this->authorizePermission(Permissions::TEAM_MANAGE);

        $user = User::where('id', $id)->where('account_id', $accountId)->first();
        $collaborator = $user
            ? Collaborator::where('user_id_member', $user->id)->where('user_id', $accountId)->first()
            : Collaborator::where('id', $id)->where('user_id', $accountId)->first();
        if (!$user && $collaborator?->user_id_member) {
            $user = User::where('id', $collaborator->user_id_member)->where('account_id', $accountId)->first();
        }
        abort_unless($user || $collaborator, 404, __('media.not_found'));

        $targets = [];
        if ($user) {
            $targets[] = [$user, 'avatar_media_id', 'profile_image'];
        }
        if ($collaborator) {
            $targets[] = [$collaborator, 'image_media_id', 'image'];
        }

        return $user ? ['user', $user->id, $targets] : ['collaborator', $collaborator->id, $targets];
    }

    private function useRequestLocale(Request $request): void
    {
        app()->setLocale($request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
    }

    private function authorizePermission(string $permission): void
    {
        abort_if(Auth::user()->cannot($permission), 403, __('media.forbidden'));
    }
}
