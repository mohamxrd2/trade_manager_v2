<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "stats-paiements" (puis fix/analytics-zero) : agrège les 7
 * indicateurs-clés (CA, nombre de ventes, panier moyen, articles/panier,
 * dépenses, bénéfice net, articles vendus), la répartition par moyen de
 * paiement, le heatmap d'affluence, le top variations et la trésorerie —
 * pour une période donnée, en un seul passage.
 *
 * ROBUSTESSE BACKFILL (bug corrigé ici) : la version précédente
 * interrogeait exclusivement la table `operations`, qui ne contient les
 * ventes historiques QUE si `operations:backfill --force` a été exécuté.
 * Tant que ce n'est pas le cas (ex. production jamais migrée), ces
 * requêtes ne trouvaient rien — d'où un chiffre d'affaires/nombre de
 * ventes à zéro alors que les transactions existent bel et bien.
 *
 * Toutes les requêtes partent maintenant de `transactions` (la source de
 * vérité qui existe TOUJOURS, backfill ou non), avec un LEFT JOIN vers
 * `operations` uniquement pour enrichir trois choses quand une opération
 * existe : le moyen de paiement (chips de l'encaissement, prioritaire sur
 * celui de la ligne), la date (`occurred_at`, sinon `transactions.
 * created_at`) et le regroupement d'un panier multi-lignes en UNE seule
 * vente (COUNT DISTINCT COALESCE(operation_id, id) — une ligne orpheline
 * sans opération compte pour 1 vente à elle seule, un panier de 3 lignes
 * compte pour 1 vente aussi).
 */
class AnalyticsSummaryService
{
    public const PAYMENT_METHODS = ['especes', 'orange_money', 'moov_money', 'wave', 'mtn_money', 'virement', 'autre'];

    public function summary(string $userId, string $period, ?string $from = null, ?string $to = null): array
    {
        $resolved = AnalyticsPeriod::resolve($period, $from, $to);
        $start = $resolved['start'];
        $end = $resolved['end'];
        $prevStart = $resolved['prev_start'];
        $prevEnd = $resolved['prev_end'];
        $granularity = $resolved['granularity'];

        $kpis = $this->kpis($userId, $start, $end, $prevStart, $prevEnd);
        $paymentMethods = $this->paymentMethods($userId, $start, $end, $prevStart, $prevEnd, $granularity);
        $heatmap = $this->heatmap($userId, $start, $end);
        $topVariations = $this->topVariations($userId, $start, $end);
        $treasury = $this->treasury($userId, $start, $end);
        $byMember = $this->byMember($userId, $start, $end);

        $paymentMethodsTotal = array_sum(array_column($paymentMethods['rows'], 'amount'));

        return [
            'period' => $resolved['period'],
            'start_date' => $start->toISOString(),
            'end_date' => $end->toISOString(),
            'kpis' => $kpis,
            'payment_methods' => $paymentMethods,
            'heatmap' => $heatmap,
            'top_variations' => $topVariations,
            'treasury' => $treasury,
            'by_member' => $byMember,
            // Vérification de cohérence exposée dans la réponse — permet de
            // détecter immédiatement une future divergence entre les deux
            // sources (ex. un futur changement qui recasserait l'une des deux
            // requêtes sans casser l'autre), plutôt que de le découvrir en
            // production comme pour le bug corrigé ici.
            'consistency' => [
                'net_profit_matches' => abs($kpis['net_profit']['value'] - round($kpis['revenue']['value'] - $kpis['expenses']['value'], 2)) < 0.01,
                'payment_methods_total_matches' => abs($paymentMethodsTotal - $kpis['revenue']['value']) < 0.01,
            ],
        ];
    }

    /**
     * Base commune à toutes les agrégations : transactions non supprimées
     * de ce type, pour ce compte, datées via COALESCE(operations.
     * occurred_at, transactions.created_at) dans la période. LEFT JOIN
     * (jamais INNER) : une ligne SANS opération reste incluse.
     */
    private function baseQuery(string $userId, string $type, Carbon $start, Carbon $end)
    {
        return DB::table('transactions')
            ->leftJoin('operations', 'operations.id', '=', 'transactions.operation_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', $type)
            ->whereNull('transactions.deleted_at')
            ->where(function ($q) {
                // Une opération liée mais soft-deleted = vente annulée : exclue.
                // Pas d'opération du tout = ligne orpheline (backfill pas encore
                // passé) : jamais exclue pour cette seule raison.
                $q->whereNull('transactions.operation_id')->orWhereNull('operations.deleted_at');
            })
            ->whereRaw('COALESCE(operations.occurred_at, transactions.created_at) BETWEEN ? AND ?', [$start, $end]);
    }

    private function dateExpression(): string
    {
        return 'COALESCE(operations.occurred_at, transactions.created_at)';
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
            'items_sold' => $this->withVariation($current['items_sold'], $previous['items_sold']),
            'expenses' => $this->withVariation($current['expenses'], $previous['expenses']),
            'net_profit' => $this->withVariation($current['net_profit'], $previous['net_profit']),
        ];
    }

    private function kpiRaw(string $userId, Carbon $start, Carbon $end): array
    {
        $salesRow = $this->baseQuery($userId, 'sale', $start, $end)
            ->selectRaw('
                COALESCE(SUM(transactions.amount), 0) as revenue,
                COALESCE(SUM(transactions.quantity), 0) as items,
                COUNT(DISTINCT COALESCE(transactions.operation_id, transactions.id)) as sales_count
            ')
            ->first();

        $expensesRow = $this->baseQuery($userId, 'expense', $start, $end)
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) as total')
            ->first();

        $revenue = (float) $salesRow->revenue;
        $items = (float) $salesRow->items;
        $salesCount = (int) $salesRow->sales_count;
        $expenses = (float) $expensesRow->total;

        return [
            'revenue' => $revenue,
            'sales_count' => $salesCount,
            'average_basket' => $salesCount > 0 ? $revenue / $salesCount : 0.0,
            'items_per_basket' => $salesCount > 0 ? $items / $salesCount : 0.0,
            'items_sold' => $items,
            'expenses' => $expenses,
            'net_profit' => $revenue - $expenses,
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
     * Le moyen de paiement vient de l'opération liée si elle existe
     * (prioritaire — c'est le moyen CONFIRMÉ à l'encaissement), sinon de la
     * ligne elle-même (transactions.payment_method, renseigné pour les
     * apports/retraits et les ventes à une ligne), sinon "non_precise"
     * (anciennes ventes sans aucun des deux).
     *
     * @return array<string, array{amount: float, count: int}>
     */
    private function paymentMethodRows(string $userId, Carbon $start, Carbon $end): array
    {
        // GROUP BY l'EXPRESSION complète, pas l'alias "payment_method" — cet
        // alias est ambigu sous Postgres car il porte le même nom qu'une
        // colonne réelle présente à la fois dans `operations` ET
        // `transactions` (déjà rencontré pour "article_id", voir commit
        // précédent sur top_variations).
        $paymentMethodExpr = "COALESCE(operations.payment_method, transactions.payment_method, 'non_precise')";

        $results = $this->baseQuery($userId, 'sale', $start, $end)
            ->selectRaw("
                {$paymentMethodExpr} as payment_method,
                SUM(transactions.amount) as amount,
                COUNT(DISTINCT COALESCE(transactions.operation_id, transactions.id)) as count
            ")
            ->groupByRaw($paymentMethodExpr)
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
     * 7j/30j/90j, mois pour 12 mois, tranche de 2h pour aujourd'hui).
     */
    private function paymentMethodSeries(string $userId, Carbon $start, Carbon $end, string $granularity): array
    {
        $bucketExpr = $this->bucketExpression($granularity);

        $paymentMethodExpr = "COALESCE(operations.payment_method, transactions.payment_method, 'non_precise')";

        $results = $this->baseQuery($userId, 'sale', $start, $end)
            ->selectRaw("{$bucketExpr} as bucket, {$paymentMethodExpr} as payment_method, SUM(transactions.amount) as amount")
            ->groupByRaw("{$bucketExpr}, {$paymentMethodExpr}")
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
        $dateExpr = $this->dateExpression();

        if ($granularity === 'hour2') {
            // Tranche de 2h : 0,2,4,...,22 — même heure locale que les
            // bornes de période (voir note de classe sur le fuseau).
            return $driver === 'pgsql'
                ? "(FLOOR(EXTRACT(HOUR FROM {$dateExpr}) / 2) * 2)"
                : "(CAST(strftime('%H', {$dateExpr}) AS INTEGER) / 2 * 2)";
        }

        if ($granularity === 'month') {
            return $driver === 'pgsql'
                ? "TO_CHAR({$dateExpr}, 'YYYY-MM')"
                : "strftime('%Y-%m', {$dateExpr})";
        }

        return $driver === 'pgsql'
            ? "TO_CHAR({$dateExpr}, 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', {$dateExpr})";
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
        $dateExpr = $this->dateExpression();

        if ($driver === 'pgsql') {
            $dowExpr = "EXTRACT(DOW FROM {$dateExpr})";
            $hourExpr = "EXTRACT(HOUR FROM {$dateExpr})";
        } else {
            $dowExpr = "CAST(strftime('%w', {$dateExpr}) AS INTEGER)";
            $hourExpr = "CAST(strftime('%H', {$dateExpr}) AS INTEGER)";
        }

        $rows = $this->baseQuery($userId, 'sale', $start, $end)
            ->selectRaw("{$dowExpr} as day_of_week, {$hourExpr} as hour, SUM(transactions.amount) as revenue, COUNT(DISTINCT COALESCE(transactions.operation_id, transactions.id)) as sales_count")
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
     * seul nom, sans nom de variation. Entièrement indépendant de la
     * présence d'une opération (jamais joint via `operations` ici, sauf
     * pour la date — transactions.article_id/variable_id existent sur
     * CHAQUE ligne, backfill ou non).
     */
    private function topVariations(string $userId, Carbon $start, Carbon $end): array
    {
        $rows = $this->baseQuery($userId, 'sale', $start, $end)
            ->leftJoin('variations', 'variations.id', '=', 'transactions.variable_id')
            ->leftJoin('articles', 'articles.id', '=', 'transactions.article_id')
            ->selectRaw('
                COALESCE(transactions.variable_id, transactions.article_id) as group_key,
                transactions.article_id as article_id,
                COALESCE(articles.name, transactions.name) as article_name,
                variations.name as variation_name,
                SUM(transactions.quantity) as total_quantity,
                SUM(transactions.amount) as total_amount
            ')
            ->groupBy('group_key', 'transactions.article_id', 'article_name', 'variation_name')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'article_id' => $row->article_id,
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
        $deposits = (float) $this->baseQuery($userId, 'apport', $start, $end)->sum('transactions.amount');
        $withdrawals = (float) $this->baseQuery($userId, 'retrait', $start, $end)->sum('transactions.amount');

        return [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'balance' => round($this->balanceAsOf($userId, $end), 2),
        ];
    }

    // --- Répartition par membre ---------------------------------------------

    /**
     * Chantier "Équipe" étape 2 — montant, nombre de ventes et panier moyen
     * PAR membre de l'équipe (gated par analytics.view au contrôleur).
     * `COALESCE(operations.created_by_user_id, transactions.user_id)` :
     * une ligne orpheline sans opération (avant backfill) ou sans auteur
     * connu est attribuée au PROPRIÉTAIRE du compte, jamais ignorée.
     */
    private function byMember(string $userId, Carbon $start, Carbon $end): array
    {
        $rows = $this->baseQuery($userId, 'sale', $start, $end)
            ->selectRaw('
                COALESCE(operations.created_by_user_id, transactions.user_id) as member_id,
                SUM(transactions.amount) as amount,
                COUNT(DISTINCT COALESCE(transactions.operation_id, transactions.id)) as sales_count
            ')
            ->groupByRaw('COALESCE(operations.created_by_user_id, transactions.user_id)')
            ->get();

        $memberIds = $rows->pluck('member_id')->filter()->unique()->values()->all();
        $users = User::whereIn('id', $memberIds)->get(['id', 'first_name', 'last_name', 'username'])->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            $user = $users->get($row->member_id);
            $name = $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : null;
            $amount = (float) $row->amount;
            $salesCount = (int) $row->sales_count;

            return [
                'user_id' => $row->member_id,
                'name' => ($name !== null && $name !== '') ? $name : ($user->username ?? null),
                'amount' => round($amount, 2),
                'sales_count' => $salesCount,
                'average_basket' => $salesCount > 0 ? round($amount / $salesCount, 2) : 0.0,
            ];
        })->sortByDesc('amount')->values()->all();
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
