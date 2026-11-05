<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\LogoOptimizer;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Convertit les logos déjà enregistrés (avant LogoOptimizer) en WebP 512 px.
 * Le nouveau fichier est écrit et enregistré AVANT la suppression de
 * l'ancien ; un logo déjà léger (< 100 Ko) ou illisible est laissé tel quel.
 *
 *   php artisan logos:optimize --dry-run   (n'écrit rien)
 *   php artisan logos:optimize
 */
class OptimizeLogos extends Command
{
    protected $signature = 'logos:optimize {--dry-run : Affiche ce qui serait fait sans rien modifier}';

    protected $description = 'Redimensionne et convertit en WebP les logos d\'entreprise existants';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');
        $saved = 0;

        Company::whereNotNull('logo')->each(function (Company $company) use ($disk, $dryRun, &$saved) {
            $old = $company->logo;
            if (!$disk->exists($old) || $disk->size($old) < 100 * 1024) {
                return;
            }

            $webp = LogoOptimizer::toWebp(new UploadedFile($disk->path($old), basename($old), null, null, true));
            if ($webp === null) {
                $this->warn("Illisible, conservé : {$old}");

                return;
            }

            $before = $disk->size($old);
            $saved += $before - strlen($webp);
            $this->line(sprintf('%s : %d Ko -> %d Ko', $old, $before / 1024, strlen($webp) / 1024));

            if ($dryRun) {
                return;
            }

            $new = 'company-logos/' . pathinfo($old, PATHINFO_FILENAME) . '_opt.webp';
            $disk->put($new, $webp);
            $company->forceFill(['logo' => $new])->save();
            $disk->delete($old);
        });

        $this->info(($dryRun ? '[dry-run] ' : '') . 'Gain total : ' . round($saved / 1024) . ' Ko');

        return self::SUCCESS;
    }
}
