<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Résumé des Core Web Vitals réels : p75 par métrique et par page sur les N
 * derniers jours, comparé aux seuils « bons » de Google.
 *
 *   php artisan web-vitals:report            (7 derniers jours)
 *   php artisan web-vitals:report --days=30 --device=mobile
 */
class WebVitalsReport extends Command
{
    protected $signature = 'web-vitals:report {--days=7} {--device=}';

    protected $description = 'p75 des Core Web Vitals mesurés chez les utilisateurs';

    /** Seuils « bon » de Google (ms, sauf CLS). */
    private const GOOD = ['LCP' => 2500, 'INP' => 200, 'CLS' => 0.1, 'FCP' => 1800, 'TTFB' => 800];

    public function handle(): int
    {
        $query = DB::table('web_vitals')->where('created_at', '>=', now()->subDays((int) $this->option('days')));
        if ($this->option('device')) {
            $query->where('device', $this->option('device'));
        }

        $rows = $query->get(['name', 'value', 'page']);
        if ($rows->isEmpty()) {
            $this->info('Aucune mesure sur la période.');

            return self::SUCCESS;
        }

        $table = [];
        foreach ($rows->groupBy(fn ($r) => $r->page . '|' . $r->name) as $key => $group) {
            [$page, $name] = explode('|', $key);
            $p75 = $this->percentile($group->pluck('value')->all(), 0.75);
            $table[] = [
                $page,
                $name,
                $name === 'CLS' ? round($p75, 3) : round($p75) . ' ms',
                $group->count(),
                $p75 <= self::GOOD[$name] ? 'bon' : 'à améliorer',
            ];
        }

        usort($table, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->table(['Page', 'Métrique', 'p75', 'Mesures', 'Verdict'], $table);

        return self::SUCCESS;
    }

    private function percentile(array $values, float $p): float
    {
        sort($values);
        $index = (int) ceil($p * count($values)) - 1;

        return (float) $values[max(0, $index)];
    }
}
