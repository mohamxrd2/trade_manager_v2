<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Logo d'entreprise : redimensionné à MAX_SIZE px (plus grand côté) et
 * converti en WebP à l'envoi. Il s'affiche en petit (barre latérale, ticket,
 * PDF de facture où il est intégré en base64) : un logo de 2 Mo alourdissait
 * chaque page et chaque PDF. Transparence conservée.
 *
 * Repli : si GD ne sait pas lire l'image ou écrire du WebP, le fichier
 * d'origine est stocké tel quel (comportement d'avant).
 */
class LogoOptimizer
{
    public const MAX_SIZE = 512;

    private const QUALITY = 85;

    /** Stocke le logo sur le disque public et renvoie son chemin. */
    public static function store(UploadedFile $file, string $directory, string $basename): string
    {
        $webp = self::toWebp($file);

        if ($webp !== null) {
            $path = "{$directory}/{$basename}.webp";
            Storage::disk('public')->put($path, $webp);

            return $path;
        }

        return $file->storeAs($directory, $basename . '.' . $file->getClientOriginalExtension(), 'public');
    }

    /** Contenu WebP redimensionné, ou null si la conversion est impossible. */
    public static function toWebp(UploadedFile $file): ?string
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                return null;
            }

            $source = imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if ($source === false) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::MAX_SIZE / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $ok = imagewebp($target, null, self::QUALITY);
            $contents = ob_get_clean();

            return $ok && $contents !== '' ? $contents : null;
        } catch (\Throwable $e) {
            Log::warning('Conversion du logo en WebP impossible, fichier d\'origine conservé', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
