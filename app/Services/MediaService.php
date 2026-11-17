<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée UNIQUE pour les images : envoi, URL, suppression,
 * rattachement. Le reste du code ne parle jamais du disque ni du
 * fournisseur (Cloudflare R2 aujourd'hui, via le disque « media ») : en
 * changer ne touche que ce fichier et config/filesystems.php.
 *
 * Chaque image est ré-encodée en WebP ici, quoi qu'ait fait le navigateur :
 * le fichier stocké ne contient que des pixels (ni métadonnées, ni GPS, ni
 * contenu caché), aux dimensions maximales de sa catégorie.
 * Chemin : {account_id}/{kind}/{uuid}.webp — jamais le nom d'origine.
 */
class MediaService
{
    public const DISK = 'media';

    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /**
     * Valide, ré-encode et enregistre l'image. Rattachée à rien : voir attach().
     *
     * @throws ValidationException fichier refusé ou limite du compte atteinte
     */
    public function upload(UploadedFile $file, string $kind, string $accountId, ?string $userId): Media
    {
        $this->assertWithinQuota($accountId);

        $mime = $this->detectMime($file);
        if (!in_array($mime, config('media.accepted_mimes'), true)) {
            throw ValidationException::withMessages(['file' => __('media.invalid_type')]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (!$image) {
            throw ValidationException::withMessages(['file' => __('media.invalid_type')]);
        }

        try {
            if ($mime === 'image/jpeg') {
                $image = $this->applyExifOrientation($image, $file->getRealPath());
            }
            $image = $this->resize($image, (int) config("media.max_dimensions.$kind", 1200));

            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            ob_start();
            imagewebp($image, null, (int) config('media.webp_quality', 80));
            $bytes = (string) ob_get_clean();
            $width = imagesx($image);
            $height = imagesy($image);
        } finally {
            unset($image);
        }

        $path = "$accountId/$kind/" . Str::uuid() . '.webp';
        Storage::disk(self::DISK)->put($path, $bytes, [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
            // Un fichier n'est jamais modifié (un remplacement crée un nouveau nom) :
            // navigateurs et CDN peuvent le garder un an sans revalider.
            'CacheControl' => self::CACHE_CONTROL,
        ]);

        return Media::create([
            'account_id' => $accountId,
            'kind' => $kind,
            'disk' => self::DISK,
            'path' => $path,
            'url' => $this->url($path),
            'width' => $width,
            'height' => $height,
            'size_bytes' => strlen($bytes),
            'mime' => 'image/webp',
            'created_by_user_id' => $userId,
        ]);
    }

    public function url(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    /** Contenu brut de l'image (factures PDF : le logo y est intégré). */
    public function contents(Media $media): ?string
    {
        try {
            return Storage::disk($media->disk)->get($media->path);
        } catch (\Throwable $e) {
            Log::warning('Image illisible', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Rattache $media à chaque cible [modèle, colonne *_media_id, colonne URL]
     * (un membre d'équipe = son compte d'accès ET sa fiche associé). L'image
     * précédente est supprimée, fichier compris.
     *
     * @param  array<int, array{0: Model, 1: string, 2: string}>  $targets
     */
    public function attach(Media $media, string $ownerType, string $ownerId, array $targets): void
    {
        $previous = [];

        DB::transaction(function () use ($media, $ownerType, $ownerId, $targets, &$previous) {
            foreach ($targets as [$model, $idColumn, $urlColumn]) {
                if ($model->{$idColumn} && $model->{$idColumn} !== $media->id) {
                    $previous[] = $model->{$idColumn};
                }
                $model->forceFill([$idColumn => $media->id, $urlColumn => $media->url])->save();
            }
            $media->update(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        });

        foreach (Media::whereIn('id', array_unique($previous))->get() as $old) {
            $this->delete($old);
        }
    }

    /**
     * Retire l'image des cibles (colonnes remises à null) puis la supprime.
     *
     * @param  array<int, array{0: Model, 1: string, 2: string}>  $targets
     */
    public function detach(array $targets): void
    {
        $ids = [];
        foreach ($targets as [$model, $idColumn, $urlColumn]) {
            if ($model->{$idColumn}) {
                $ids[] = $model->{$idColumn};
            }
            $model->forceFill([$idColumn => null, $urlColumn => null])->save();
        }

        foreach (Media::whereIn('id', array_unique($ids))->get() as $media) {
            $this->delete($media);
        }
    }

    /** Supprime le fichier puis la ligne (les colonnes *_media_id passent à null). */
    public function delete(Media $media): void
    {
        try {
            Storage::disk($media->disk)->delete($media->path);
        } catch (\Throwable $e) {
            Log::warning('Suppression d\'image impossible', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        }
        $media->delete();
    }

    /** Supprime les images d'un élément supprimé (article, variation…). */
    public function deleteForOwner(string $ownerType, string $ownerId): void
    {
        // Appelé par les événements des modèles : rien à faire tant que la
        // table n'existe pas (base pas encore migrée, schémas de test partiels).
        if (!\Illuminate\Support\Facades\Schema::hasTable('media')) {
            return;
        }

        foreach (Media::where('owner_type', $ownerType)->where('owner_id', $ownerId)->get() as $media) {
            $this->delete($media);
        }
    }

    /**
     * Photo d'un nouvel article jamais enregistré : supprimée après
     * media.orphan_ttl_hours (appelé à chaque envoi du compte, pas de tâche
     * planifiée à faire tourner).
     */
    public function pruneOrphans(string $accountId): void
    {
        Media::where('account_id', $accountId)
            ->whereNull('owner_id')
            ->where('created_at', '<', now()->subHours((int) config('media.orphan_ttl_hours', 24)))
            ->get()
            ->each(fn (Media $media) => $this->delete($media));
    }

    /**
     * Image envoyée sans rattachement (écran « Nouvel article »), réclamée à
     * la création : elle doit venir du même compte, être de la bonne sorte
     * et n'appartenir encore à rien. Sinon null (ignorée).
     */
    public function claim(?string $mediaId, string $accountId, string $kind): ?Media
    {
        if (!$mediaId) {
            return null;
        }

        return Media::where('id', $mediaId)
            ->where('account_id', $accountId)
            ->where('kind', $kind)
            ->whereNull('owner_id')
            ->first();
    }

    private function assertWithinQuota(string $accountId): void
    {
        $usage = Media::where('account_id', $accountId)
            ->selectRaw('COUNT(*) as files, COALESCE(SUM(size_bytes), 0) as bytes')
            ->first();

        if ((int) $usage->files >= (int) config('media.max_per_account')
            || (int) $usage->bytes >= (int) config('media.max_bytes_per_account')) {
            throw ValidationException::withMessages(['file' => __('media.quota_reached')]);
        }
    }

    /** Type réel, d'après le contenu (jamais l'extension ni le type annoncé). */
    private function detectMime(UploadedFile $file): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath());

        return is_string($mime) ? $mime : null;
    }

    /** Les photos de téléphone sont souvent stockées couchées, avec une consigne EXIF. */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        return $rotated ?: $image;
    }

    private function resize(\GdImage $image, int $max): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $max && $height <= $max) {
            return $image;
        }

        $ratio = $max / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }
}
