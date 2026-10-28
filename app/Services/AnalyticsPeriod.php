<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Résolution de période UNIQUE pour toute la page Analyses — avant ce
 * fichier, deux implémentations distinctes (AnalyticsController::
 * calculateDateRange() pour les anciens endpoints, AnalyticsSummaryService::
 * resolvePeriod() pour le nouveau) calculaient chacune leurs propres bornes,
 * ce qui rendait toute incohérence entre endpoints difficile à diagnostiquer.
 *
 * Accepte les alias historiques du frontend (today/7/30/90) ET les tokens
 * explicites (7d/30d/90d/12m/3m/"12 mois") — une valeur qui ne correspond à
 * aucun de ces alias lève une InvalidArgumentException (le contrôleur la
 * traduit en 422), jamais un repli silencieux sur une autre période.
 */
class AnalyticsPeriod
{
    public const TIMEZONE = 'Africa/Abidjan';

    private const ALIASES = [
        'today' => 'today',
        '7' => '7d',
        '7d' => '7d',
        '30' => '30d',
        '30d' => '30d',
        '90' => '90d',
        '90d' => '90d',
        '3m' => '90d',
        '365' => '12m',
        '12m' => '12m',
        '12 mois' => '12m',
        '12mois' => '12m',
        'custom' => 'custom',
    ];

    /**
     * @return array{period: string, start: Carbon, end: Carbon, prev_start: Carbon, prev_end: Carbon, granularity: string}
     */
    public static function resolve(string $period, ?string $from = null, ?string $to = null): array
    {
        $canonical = self::canonicalize($period);
        $now = Carbon::now(self::TIMEZONE);

        if ($canonical === 'custom') {
            if (!$from || !$to) {
                throw new \InvalidArgumentException("La période 'custom' nécessite les paramètres from et to.");
            }

            $start = Carbon::parse($from, self::TIMEZONE)->startOfDay();
            $end = Carbon::parse($to, self::TIMEZONE)->endOfDay();

            if ($start->greaterThan($end)) {
                throw new \InvalidArgumentException('from doit être antérieur ou égal à to.');
            }

            $granularity = $start->diffInDays($end) > 60 ? 'month' : 'day';
        } else {
            [$start, $end, $granularity] = match ($canonical) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'hour2'],
                '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), 'day'],
                '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), 'day'],
                '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay(), 'day'],
                '12m' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfDay(), 'month'],
            };
        }

        // Période précédente de même durée, immédiatement avant.
        $durationSeconds = $start->diffInSeconds($end) + 1;
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($durationSeconds - 1);

        return [
            'period' => $canonical,
            'start' => $start,
            'end' => $end,
            'prev_start' => $prevStart,
            'prev_end' => $prevEnd,
            'granularity' => $granularity,
        ];
    }

    public static function canonicalize(string $period): string
    {
        $normalized = strtolower(trim($period));

        if (!array_key_exists($normalized, self::ALIASES)) {
            throw new \InvalidArgumentException("Période invalide : {$period}");
        }

        return self::ALIASES[$normalized];
    }
}
