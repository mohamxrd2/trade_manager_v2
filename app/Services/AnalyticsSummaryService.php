<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "stats-paiements" : agrège les 5 blocs de la page Analyses
 * (kpis, répartition par moyen de paiement, heatmap d'affluence, top
 * variations, trésorerie) pour une période donnée, en un seul passage.
 *
 * Toutes les requêtes passent par le query builder (DB::table) avec un
 * filtre explicite `whereNull('deleted_at')` sur chaque table jointe — un
 * join brut ne respecte pas le global scope SoftDeletes d'Eloquent, donc ce
 * filtre doit être posé à la main partout où on joint `operations` ou
 * `transactions`.
 *
 * Fuseau horaire : Carbon::now('Africa/Abidjan') explicite pour les bornes
 * de période (actuellement équivalent à UTC vu la config app.timezone, mais
 * d'autres fuseaux n'ont pas cette coïncidence — autant être explicite).
 * Les colonnes `occurred_at` elles-même ne sont PAS converties via une
 * fonction SQL de fuseau : elles sont stockées comme de l'UTC qui coïncide
 * numériquement avec l'heure d'Abidjan, donc une extraction directe
 * (EXTRACT/strftime) donne déjà la bonne heure locale.
 */
class AnalyticsSummaryService
{
    public const PAYMENT_METHODS = ['especes', 'orange_money', 'moov_money', 'wave', 'mtn_money', 'virement', 'autre'];

    private const TIMEZONE = 'Africa/Abidjan';

    public function summary(string $userId, string $period): array
    {
        [$start, $end, $prevStart, $prevEnd, $granularity] = $this->resolvePeriod($period);

        return [
            'period' => $period,
            'start_date' => $start->toISOString(),
            'end_date' => $end->toISOString(),
            'kpis' => $this->kpis($userId, $start, $end, $prevStart, $prevEnd),
            'payment_methods' => $this->paymentMethods($userId, $start, $end, $prevStart, $prevEnd, $granularity),
            'heatmap' => $this->heatmap($userId, $start, $end),
            'top_variations' => $this->topVariations($userId, $start, $end),
            'treasury' => $this->treasury($userId, $start, $end),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: string}
     */
    private function resolvePeriod(string $period): array
    {
        $now = Carbon::now(self::TIMEZONE);

        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $granularity = 'hour2';
                break;
            case '7d':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                $granularity = 'day';
                break;
            case '30d':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                $granularity = 'day';
                break;
            case '12m':
                $start = $now->copy()->subMonthsNoOverflow(11)->startOfMonth();
                $end = $now->copy()->endOfDay();
                $granularity = 'month';
                break;
            default:
                throw new \InvalidArgumentException("Période invalide : {$period}");
        }

        // Période précédente de même durée, immédiatement avant.
        $durationSeconds = $start->diffInSeconds($end) + 1;
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($durationSeconds - 1);

        return [$start, $end, $prevStart, $prevEnd, $granularity];
    }

    // --- KPIs -------------------------------------------------------------

    private function kpis(string $userId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $current = $this->kpiRaw($userId, $start, $end);
        $previous = $this->kpiRaw($userId, $prevStart, $prevEnd);

        return [
            'revenue' => $this->withVariation($current['revenue'], $previous['revenue']),
            'sales_count' => $this->withVariation($current['sales_count'], $previous['sales_count']),
            'average_basket' => $this->withVariation($current['average_basket'], $previous['average_basket']),
            'items_per_basket' => $this->withVariation($current['items_per_basket'], $previous['items_per_basket']),
        ];
    }

    /**
     * Chiffre d'affaires = somme des opérations de vente (le total de
     * l'opération, pas la somme des lignes recalculée) ; nombre de ventes =
     * nombre d'OPÉRATIONS, pas de lignes (un panier à 3 articles = 1 vente),
     * convention déjà établie dans AnalyticsController::kpis().
     */
    private function kpiRaw(string $userId, Carbon $start, Carbon $end): array
    {
        $row = DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as sales_count')
            ->first();

        $revenue = (float) $row->revenue;
        $salesCount = (int) $row->sales_count;

        $itemsRow = DB::table('transactions')
            ->join('operations', 'operations.id', '=', 'transactions.operation_id')
            ->where('operations.user_id', $userId)
            ->where('operations.type', 'sale')
            ->whereNull('operations.deleted_at')
            ->whereNull('transactions.deleted_at')
            ->whereBetween('operations.occurred_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(transactions.quantity), 0) as total_items')
            ->first();

        $totalItems = (float) $itemsRow->total_items;

        return [
            'revenue' => $revenue,
            'sales_count' => $salesCount,
            'average_basket' => $salesCount > 0 ? $revenue / $salesCount : 0.0,
            'items_per_basket' => $salesCount > 0 ? $totalItems / $salesCount : 0.0,
        ];
    }

    private function withVariation(float $current, float $previous): array
    {
        if ($previous != 0.0) {
            $changePercent = (($current - $previous) / $previous) * 100;
        } else {
            $changePercent = $current > 0 ? 100.0 : 0.0;
        }

        return [
            'value' => round($current, 2),
            'previous' => round($previous, 2),
            'change_percent' => round($changePercent, 2),
        ];
    }

    // --- Moyens de paiement -------------------------------------------------

    private function paymentMethods(string $userId, Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd, string $granularity): array
    {
        $current = $this->paymentMethodRows($userId, $start, $end);
        $previous = $this->paymentMethodRows($userId, $prevStart, $prevEnd);

        $totalRevenue = array_sum(array_map(fn ($row) => $row['amount'], $current));

        $methods = array_unique(array_merge(array_keys($current), array_keys($previous)));

        $rows = [];
        foreach ($methods as $method) {
            $cur = $current[$method] ?? ['amount' => 0.0, 'count' => 0];
            $prev = $previous[$method] ?? ['amount' => 0.0, 'count' => 0];

            $changePercent = $prev['amount'] != 0.0
                ? (($cur['amount'] - $prev['amount']) / $prev['amount']) * 100
                : ($cur['amount'] > 0 ? 100.0 : 0.0);

            $rows[] = [
                'payment_method' => $method,
                'amount' => round($cur['amount'], 2),
                'percentage' => $totalRevenue > 0 ? round($cur['amount'] / $totalRevenue * 100, 2) : 0.0,
                'sales_count' => $cur['count'],
                'average_basket' => $cur['count'] > 0 ? round($cur['amount'] / $cur['count'], 2) : 0.0,
                'change_percent' => round($changePercent, 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'rows' => $rows,
            'series' => $this->paymentMethodSeries($userId, $start, $end, $granularity),
        ];
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function paymentMethodRows(string $userId, Carbon $start, Carbon $end): array
    {
        $results = DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw("COALESCE(payment_method, 'non_precise') as payment_method, SUM(total_amount) as amount, COUNT(*) as count")
            ->groupBy('payment_method')
            ->get();

        $map = [];
        foreach ($results as $row) {
            $map[$row->payment_method] = ['amount' => (float) $row->amount, 'count' => (int) $row->count];
        }

        return $map;
    }

    /**
     * Série temporelle montant par méthode par intervalle — mêmes
     * intervalles que la courbe "Tendance des ventes" existante (jour pour
     * 7j/30j, mois pour 12 mois, tranche de 2h pour aujourd'hui).
     */
    private function paymentMethodSeries(string $userId, Carbon $start, Carbon $end, string $granularity): array
    {
        $bucketExpr = $this->bucketExpression($granularity);

        $results = DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw("{$bucketExpr} as bucket, COALESCE(payment_method, 'non_precise') as payment_method, SUM(total_amount) as amount")
            ->groupBy('bucket', 'payment_method')
            ->orderBy('bucket')
            ->get();

        $buckets = [];
        foreach ($results as $row) {
            $bucket = (string) $row->bucket;
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = ['bucket' => $bucket, 'amounts' => []];
            }
            $buckets[$bucket]['amounts'][$row->payment_method] = round((float) $row->amount, 2);
        }

        return array_values($buckets);
    }

    private function bucketExpression(string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        if ($granularity === 'hour2') {
            // Tranche de 2h : 0,2,4,...,22 — même heure locale que les
            // bornes de période (voir note de classe sur le fuseau).
            return $driver === 'pgsql'
                ? "(FLOOR(EXTRACT(HOUR FROM occurred_at) / 2) * 2)"
                : "(CAST(strftime('%H', occurred_at) AS INTEGER) / 2 * 2)";
        }

        if ($granularity === 'month') {
            return $driver === 'pgsql'
                ? "TO_CHAR(occurred_at, 'YYYY-MM')"
                : "strftime('%Y-%m', occurred_at)";
        }

        return $driver === 'pgsql'
            ? "TO_CHAR(occurred_at, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', occurred_at)";
    }

    // --- Heures d'affluence -------------------------------------------------

    /**
     * Jour de semaine × heure. Convention 0 = dimanche … 6 = samedi,
     * identique entre `EXTRACT(DOW FROM ...)` (pgsql) et `strftime('%w', ...)`
     * (sqlite) — pas de décalage à corriger entre drivers.
     */
    private function heatmap(string $userId, Carbon $start, Carbon $end): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $dowExpr = 'EXTRACT(DOW FROM occurred_at)';
            $hourExpr = 'EXTRACT(HOUR FROM occurred_at)';
        } else {
            $dowExpr = "CAST(strftime('%w', occurred_at) AS INTEGER)";
            $hourExpr = "CAST(strftime('%H', occurred_at) AS INTEGER)";
        }

        $rows = DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw("{$dowExpr} as day_of_week, {$hourExpr} as hour, SUM(total_amount) as revenue, COUNT(*) as sales_count")
            ->groupBy('day_of_week', 'hour')
            ->get();

        $cells = $rows->map(fn ($row) => [
            'day_of_week' => (int) $row->day_of_week,
            'hour' => (int) $row->hour,
            'revenue' => round((float) $row->revenue, 2),
            'sales_count' => (int) $row->sales_count,
        ])->values()->all();

        return ['cells' => $cells];
    }

    // --- Top variations -----------------------------------------------------

    /**
     * Top 10 par chiffre d'affaires. `COALESCE(variable_id, article_id)`
     * regroupe par variation précise si la ligne en a une, sinon par
     * article (cas "simple") — un article simple apparaît donc sous son
     * seul nom, sans nom de variation.
     */
    private function topVariations(string $userId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('transactions')
            ->join('operations', 'operations.id', '=', 'transactions.operation_id')
            ->leftJoin('variations', 'variations.id', '=', 'transactions.variable_id')
            ->leftJoin('articles', 'articles.id', '=', 'transactions.article_id')
            ->where('operations.user_id', $userId)
            ->where('operations.type', 'sale')
            ->whereNull('operations.deleted_at')
            ->whereNull('transactions.deleted_at')
            ->whereBetween('operations.occurred_at', [$start, $end])
            ->selectRaw('
                COALESCE(transactions.variable_id, transactions.article_id) as group_key,
                COALESCE(articles.name, transactions.name) as article_name,
                variations.name as variation_name,
                SUM(transactions.quantity) as total_quantity,
                SUM(transactions.amount) as total_amount
            ')
            ->groupBy('group_key', 'article_name', 'variation_name')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'article_name' => $row->article_name,
            'variation_name' => $row->variation_name,
            'quantity' => (int) $row->total_quantity,
            'revenue' => round((float) $row->total_amount, 2),
        ])->values()->all();
    }

    // --- Trésorerie -----------------------------------------------------------

    /**
     * Bloc séparé, jamais compté dans le chiffre d'affaires. Le solde est
     * calculé en fin de période (cumul depuis toujours), avec la même
     * formule que User::getBalanceAttribute() (sale - expense + apport -
     * retrait), pour rester cohérent avec le solde affiché sur le
     * Portefeuille/l'Accueil.
     */
    private function treasury(string $userId, Carbon $start, Carbon $end): array
    {
        $deposits = (float) DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'apport')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('total_amount');

        $withdrawals = (float) DB::table('operations')
            ->where('user_id', $userId)
            ->where('type', 'retrait')
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('total_amount');

        return [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'balance' => round($this->balanceAsOf($userId, $end), 2),
        ];
    }

    private function balanceAsOf(string $userId, Carbon $end): float
    {
        $sumType = function (string $type) use ($userId, $end) {
            return (float) DB::table('transactions')
                ->leftJoin('operations', 'operations.id', '=', 'transactions.operation_id')
                ->where('transactions.user_id', $userId)
                ->where('transactions.type', $type)
                ->whereNull('transactions.deleted_at')
                ->whereRaw('COALESCE(operations.occurred_at, transactions.created_at) <= ?', [$end])
                ->sum('transactions.amount');
        };

        return $sumType('sale') - $sumType('expense') + $sumType('apport') - $sumType('retrait');
    }
}
