<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Get overview statistics (revenu net, total ventes, total dépenses) for a period
     */
    public function overview(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30'); // 'today', '7', '30', 'year', 'custom'
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Calculer les dates selon la période
            [$start, $end] = $this->calculateDateRange($period, $startDate, $endDate);

            $userId = Auth::id();

            // Total des ventes
            $totalSales = Transaction::where('user_id', $userId)
                ->where('type', 'sale')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount') ?? 0;

            // Total des dépenses
            $totalExpenses = Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount') ?? 0;

            // Revenu net
            $netRevenue = $totalSales - $totalExpenses;

            return response()->json([
                'success' => true,
                'message' => 'Statistiques récupérées avec succès',
                'data' => [
                    'net_revenue' => (float) $netRevenue,
                    'total_sales' => (float) $totalSales,
                    'total_expenses' => (float) $totalExpenses,
                    'period' => $period,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get trends data for charts (sales & expenses over time, wallet over time)
     */
    public function trends(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30');
            $type = $request->input('type', 'both'); // 'sales_expenses', 'wallet', 'both'
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            [$start, $end] = $this->calculateDateRange($period, $startDate, $endDate);
            $userId = Auth::id();

            $data = [];

            // Group by day, week, or month based on period length
            $groupBy = $this->getGroupBy($start, $end);

            if ($type === 'both' || $type === 'sales_expenses') {
                // Sales & Expenses over time
                $salesData = Transaction::where('user_id', $userId)
                    ->where('type', 'sale')
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw($groupBy['select'])
                    ->groupBy($groupBy['group'])
                    ->orderBy($groupBy['order'])
                    ->get()
                    ->map(function ($item) {
                        return [
                            'date' => $item->date,
                            'amount' => (float) $item->total
                        ];
                    });

                $expensesData = Transaction::where('user_id', $userId)
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw($groupBy['select'])
                    ->groupBy($groupBy['group'])
                    ->orderBy($groupBy['order'])
                    ->get()
                    ->map(function ($item) {
                        return [
                            'date' => $item->date,
                            'amount' => (float) $item->total
                        ];
                    });

                $data['sales_expenses'] = [
                    'sales' => $salesData,
                    'expenses' => $expensesData
                ];
            }

            if ($type === 'both' || $type === 'wallet') {
                // Wallet (calculated_wallet) over time
                // Optimisé : utiliser une requête SQL unique au lieu d'une boucle jour par jour
                $daysDiff = $start->diffInDays($end);
                $driver = DB::connection()->getDriverName();
                
                // Pour les grandes périodes (> 90 jours), grouper par mois pour optimiser
                if ($daysDiff > 90) {
                    // Grouper par mois et calculer le wallet cumulatif
                    if ($driver === 'pgsql') {
                        $walletQuery = "
                            SELECT 
                                date,
                                SUM(sales) OVER (ORDER BY date) - SUM(expenses) OVER (ORDER BY date) as wallet
                            FROM (
                                SELECT 
                                    TO_CHAR(created_at, 'YYYY-MM-01')::date as date,
                                    SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as sales,
                                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses
                                FROM transactions
                                WHERE user_id = ?
                                    AND created_at BETWEEN ? AND ?
                                GROUP BY TO_CHAR(created_at, 'YYYY-MM-01')::date
                            ) monthly
                            ORDER BY date
                        ";
                    } else {
                        $walletQuery = "
                            SELECT 
                                date,
                                @wallet := @wallet + sales - expenses as wallet
                            FROM (
                                SELECT 
                                    DATE_FORMAT(created_at, '%Y-%m-01') as date,
                                    SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as sales,
                                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses
                                FROM transactions
                                WHERE user_id = ?
                                    AND created_at BETWEEN ? AND ?
                                GROUP BY DATE_FORMAT(created_at, '%Y-%m-01')
                            ) monthly
                            CROSS JOIN (SELECT @wallet := 0) r
                            ORDER BY date
                        ";
                    }
                    
                    $walletData = DB::select($walletQuery, [$userId, $start, $end]);
                    $data['wallet'] = array_map(function ($item) {
                        return [
                            'date' => is_object($item) ? $item->date : $item['date'],
                            'amount' => (float) (is_object($item) ? $item->wallet : $item['wallet'])
                        ];
                    }, $walletData);
                } else {
                    // Pour les petites périodes (<= 90 jours), utiliser une requête optimisée par jour
                    if ($driver === 'pgsql') {
                        $walletQuery = "
                            SELECT 
                                date,
                                SUM(sales) OVER (ORDER BY date) - SUM(expenses) OVER (ORDER BY date) as wallet
                            FROM (
                                SELECT 
                                    DATE(created_at) as date,
                                    SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as sales,
                                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses
                                FROM transactions
                                WHERE user_id = ?
                                    AND created_at BETWEEN ? AND ?
                                GROUP BY DATE(created_at)
                            ) daily
                            ORDER BY date
                        ";
                    } else {
                        $walletQuery = "
                            SELECT 
                                date,
                                @wallet := @wallet + sales - expenses as wallet
                            FROM (
                                SELECT 
                                    DATE(created_at) as date,
                                    SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as sales,
                                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses
                                FROM transactions
                                WHERE user_id = ?
                                    AND created_at BETWEEN ? AND ?
                                GROUP BY DATE(created_at)
                            ) daily
                            CROSS JOIN (SELECT @wallet := 0) r
                            ORDER BY date
                        ";
                    }
                    
                    $walletData = DB::select($walletQuery, [$userId, $start, $end]);
                    $data['wallet'] = array_map(function ($item) {
                        return [
                            'date' => is_object($item) ? $item->date : $item['date'],
                            'amount' => (float) (is_object($item) ? $item->wallet : $item['wallet'])
                        ];
                    }, $walletData);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Données de tendances récupérées avec succès',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tendances',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get category analysis (sales distribution by article type, top 5 products)
     */
    public function categoryAnalysis(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            [$start, $end] = $this->calculateDateRange($period, $startDate, $endDate);
            $userId = Auth::id();

            // Répartition des ventes par type d'article
            // Utiliser leftJoin pour gérer les cas où article_id est NULL
            $salesByType = Transaction::where('transactions.user_id', $userId)
                ->where('transactions.type', 'sale')
                ->whereBetween('transactions.created_at', [$start, $end])
                ->whereNotNull('transactions.article_id')
                ->leftJoin('articles', 'transactions.article_id', '=', 'articles.id')
                ->where('articles.user_id', $userId)
                ->whereNotNull('articles.type')
                ->selectRaw('articles.type, SUM(transactions.amount) as total')
                ->groupBy('articles.type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type' => $item->type,
                        'total' => (float) $item->total
                    ];
                });

            // Top 5 produits les plus vendus (par quantité)
            $topProducts = Transaction::where('transactions.user_id', $userId)
                ->where('transactions.type', 'sale')
                ->whereBetween('transactions.created_at', [$start, $end])
                ->whereNotNull('transactions.article_id')
                ->leftJoin('articles', 'transactions.article_id', '=', 'articles.id')
                ->where('articles.user_id', $userId)
                ->whereNotNull('articles.id')
                ->selectRaw('articles.id, articles.name, articles.type, SUM(transactions.quantity) as total_quantity, SUM(transactions.amount) as total_amount')
                ->groupBy('articles.id', 'articles.name', 'articles.type')
                ->orderByDesc('total_quantity')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'type' => $item->type,
                        'total_quantity' => (int) $item->total_quantity,
                        'total_amount' => (float) $item->total_amount
                    ];
                });

            // Calculer les pourcentages pour la répartition
            $totalSales = $salesByType->sum('total');
            $salesByTypeWithPercentage = $salesByType->map(function ($item) use ($totalSales) {
                $percentage = $totalSales > 0 ? ($item['total'] / $totalSales) * 100 : 0;
                return [
                    ...$item,
                    'percentage' => round($percentage, 2)
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Analyse par catégorie récupérée avec succès',
                'data' => [
                    'sales_by_type' => $salesByTypeWithPercentage,
                    'top_products' => $topProducts
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'analyse par catégorie',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get temporal comparisons (current period vs previous period)
     */
    public function comparisons(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            [$currentStart, $currentEnd] = $this->calculateDateRange($period, $startDate, $endDate);
            $userId = Auth::id();

            // Calculer la période précédente selon le type de période
            if ($period === 'year') {
                // Pour "Cette année", comparer avec l'année précédente complète
                $previousStart = $currentStart->copy()->subYear()->startOfYear();
                $previousEnd = $currentStart->copy()->subYear()->endOfYear();
            } elseif ($period === 'all') {
                // Pour "Depuis toujours", ne pas faire de comparaison (retourner des valeurs nulles)
                return response()->json([
                    'success' => true,
                    'message' => 'Comparaisons non disponibles pour "Depuis toujours"',
                    'data' => [
                        'sales' => [
                            'current' => 0,
                            'previous' => 0,
                            'change' => 0,
                            'change_type' => 'neutral'
                        ],
                        'expenses' => [
                            'current' => 0,
                            'previous' => 0,
                            'change' => 0,
                            'change_type' => 'neutral'
                        ],
                        'net_revenue' => [
                            'current' => 0,
                            'previous' => 0,
                            'change' => 0,
                            'change_type' => 'neutral'
                        ]
                    ]
                ]);
            } else {
                // Pour les autres périodes, calculer la période précédente de même durée
                $daysDiff = $currentStart->diffInDays($currentEnd);
                $previousEnd = $currentStart->copy()->subDay();
                $previousStart = $previousEnd->copy()->subDays($daysDiff);
            }

            // Période actuelle
            $currentSales = Transaction::where('user_id', $userId)
                ->where('type', 'sale')
                ->whereBetween('created_at', [$currentStart, $currentEnd])
                ->sum('amount') ?? 0;

            $currentExpenses = Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$currentStart, $currentEnd])
                ->sum('amount') ?? 0;

            $currentNet = $currentSales - $currentExpenses;

            // Période précédente
            $previousSales = Transaction::where('user_id', $userId)
                ->where('type', 'sale')
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->sum('amount') ?? 0;

            $previousExpenses = Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$previousStart, $previousEnd])
                ->sum('amount') ?? 0;

            $previousNet = $previousSales - $previousExpenses;

            // Calculer les variations en pourcentage
            // Formule : ((current - previous) / previous) * 100
            // Si previous = 0 et current > 0, c'est une augmentation infinie (retourner 100% ou plus selon le contexte)
            // Si previous = 0 et current = 0, pas de changement (0%)
            // Si previous > 0, calculer normalement
            
            // Ventes
            if ($previousSales == 0) {
                // Si pas de ventes précédentes mais des ventes actuelles, c'est une nouvelle donnée
                $salesChange = $currentSales > 0 ? 100 : 0;
            } else {
                // Calcul normal : ((current - previous) / previous) * 100
                $salesChange = (($currentSales - $previousSales) / $previousSales) * 100;
            }

            // Dépenses
            if ($previousExpenses == 0) {
                $expensesChange = $currentExpenses > 0 ? 100 : 0;
            } else {
                $expensesChange = (($currentExpenses - $previousExpenses) / $previousExpenses) * 100;
            }

            // Revenu net
            if ($previousNet == 0) {
                // Si pas de revenu net précédent
                if ($currentNet > 0) {
                    $netChange = 100; // Nouveau revenu positif
                } elseif ($currentNet < 0) {
                    $netChange = -100; // Nouveau revenu négatif
                } else {
                    $netChange = 0; // Toujours 0
                }
            } else {
                // Calcul normal avec abs() pour gérer les cas négatifs
                $netChange = (($currentNet - $previousNet) / abs($previousNet)) * 100;
            }

            return response()->json([
                'success' => true,
                'message' => 'Comparaisons récupérées avec succès',
                'data' => [
                    'sales' => [
                        'current' => (float) $currentSales,
                        'previous' => (float) $previousSales,
                        'change' => round($salesChange, 2),
                        'change_type' => $salesChange >= 0 ? 'increase' : 'decrease'
                    ],
                    'expenses' => [
                        'current' => (float) $currentExpenses,
                        'previous' => (float) $previousExpenses,
                        'change' => round($expensesChange, 2),
                        'change_type' => $expensesChange >= 0 ? 'increase' : 'decrease'
                    ],
                    'net_revenue' => [
                        'current' => (float) $currentNet,
                        'previous' => (float) $previousNet,
                        'change' => round($netChange, 2),
                        'change_type' => $netChange >= 0 ? 'increase' : 'decrease'
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des comparaisons',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get financial KPIs (marge nette, panier moyen, ventes moyennes par jour, taux de dépenses)
     */
    public function kpis(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            [$start, $end] = $this->calculateDateRange($period, $startDate, $endDate);
            $userId = Auth::id();

            $totalSales = Transaction::where('user_id', $userId)
                ->where('type', 'sale')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount') ?? 0;

            $totalExpenses = Transaction::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount') ?? 0;

            $salesCount = Transaction::where('user_id', $userId)
                ->where('type', 'sale')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $daysDiff = $start->diffInDays($end) + 1;

            // Marge nette = (Ventes - Dépenses) / Ventes
            $netMargin = $totalSales > 0 ? (($totalSales - $totalExpenses) / $totalSales) * 100 : 0;

            // Panier moyen = Total ventes / Nombre de ventes
            $averageBasket = $salesCount > 0 ? $totalSales / $salesCount : 0;

            // Ventes moyennes par jour
            $averageSalesPerDay = $daysDiff > 0 ? $totalSales / $daysDiff : 0;

            // Taux de dépenses = Dépenses / (Ventes + Dépenses)
            $expenseRate = ($totalSales + $totalExpenses) > 0 ? ($totalExpenses / ($totalSales + $totalExpenses)) * 100 : 0;

            return response()->json([
                'success' => true,
                'message' => 'KPI récupérés avec succès',
                'data' => [
                    'net_margin' => round($netMargin, 2),
                    'average_basket' => round($averageBasket, 2),
                    'average_sales_per_day' => round($averageSalesPerDay, 2),
                    'expense_rate' => round($expenseRate, 2),
                    'sales_count' => $salesCount,
                    'days' => $daysDiff
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des KPI',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed transactions table with filters
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', '30');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $type = $request->input('type'); // 'sale', 'expense', or null for all
            $search = $request->input('search');
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 15);

            [$start, $end] = $this->calculateDateRange($period, $startDate, $endDate);
            $userId = Auth::id();

            $query = Transaction::where('user_id', $userId)
                ->whereBetween('created_at', [$start, $end])
                ->with(['article', 'variation']);

            if ($type) {
                $query->where('type', $type);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhereHas('article', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $total = $query->count();
            $transactions = $query->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Transactions récupérées avec succès',
                'data' => [
                    'transactions' => $transactions,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'last_page' => ceil($total / $perPage)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get reorder predictions based on sales frequency
     */
    public function predictions(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();

            // Récupérer tous les articles avec leurs transactions
            $articles = Article::where('user_id', $userId)
                ->with(['transactions' => function ($query) {
                    $query->where('type', 'sale')
                        ->orderBy('created_at', 'asc');
                }])
                ->get();

            $predictions = [];

            foreach ($articles as $article) {
                $transactions = $article->transactions;
                
                if ($transactions->count() < 1) {
                    // Pas de ventes, pas de prédiction possible
                    continue;
                }

                // Calculer la quantité totale vendue
                $soldQuantity = $transactions->sum('quantity');
                
                // Calculer la quantité restante
                $remainingQuantity = $article->quantity - $soldQuantity;

                if ($remainingQuantity <= 0) {
                    // Déjà épuisé
                    $predictions[] = [
                        'article_id' => $article->id,
                        'article_name' => $article->name,
                        'type' => $article->type,
                        'current_quantity' => $article->quantity,
                        'sold_quantity' => $soldQuantity,
                        'remaining_quantity' => 0,
                        'sales_percentage' => 100,
                        'status' => 'out_of_stock',
                        'predicted_reorder_date' => null,
                        'days_until_reorder' => 0,
                        'sales_rate_per_day' => 0
                    ];
                    continue;
                }

                // Calculer le taux de vente (quantité vendue par jour en moyenne)
                $firstSale = Carbon::parse($transactions->first()->created_at);
                $lastSale = Carbon::parse($transactions->last()->created_at);
                $totalDays = $firstSale->diffInDays($lastSale);
                
                // Si toutes les ventes sont le même jour, utiliser 1 jour minimum
                if ($totalDays == 0) {
                    $totalDays = 1;
                }
                
                // Calculer le nombre de ventes en moyenne par jour
                // Formule : quantité totale vendue / nombre de jours entre première et dernière vente
                $salesRatePerDay = $soldQuantity / $totalDays;

                // Si pas de ventes par jour (impossible mais sécurité)
                if ($salesRatePerDay <= 0) {
                    continue;
                }

                // Calculer le nombre de jours restants avant la pénurie
                // Formule : quantité restante / nombre de ventes moyennes par jour
                $daysUntilSoldOut = $remainingQuantity / $salesRatePerDay;
                $predictedDate = Carbon::now()->addDays($daysUntilSoldOut);
                
                $predictions[] = [
                    'article_id' => $article->id,
                    'article_name' => $article->name,
                    'type' => $article->type,
                    'current_quantity' => $article->quantity,
                    'sold_quantity' => $soldQuantity,
                    'remaining_quantity' => $remainingQuantity,
                    'sales_percentage' => $article->quantity > 0 ? round(($soldQuantity / $article->quantity) * 100, 2) : 0,
                    'status' => 'in_stock',
                    'predicted_reorder_date' => $predictedDate->toDateString(),
                    'days_until_reorder' => (int) round($daysUntilSoldOut),
                    'sales_rate_per_day' => round($salesRatePerDay, 2)
                ];
            }

            // Trier par jours jusqu'à réapprovisionnement (plus urgent en premier)
            usort($predictions, function ($a, $b) {
                if ($a['status'] === 'out_of_stock' && $b['status'] !== 'out_of_stock') {
                    return -1;
                }
                if ($a['status'] !== 'out_of_stock' && $b['status'] === 'out_of_stock') {
                    return 1;
                }
                return $a['days_until_reorder'] <=> $b['days_until_reorder'];
            });

            return response()->json([
                'success' => true,
                'message' => 'Prédictions récupérées avec succès',
                'data' => $predictions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des prédictions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate date range based on period
     */
    private function calculateDateRange(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $end = Carbon::now()->endOfDay();
        $start = null;

        if ($period === 'custom' && $startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
        } elseif ($period === 'today') {
            $start = Carbon::today()->startOfDay();
        } elseif ($period === '7') {
            $start = Carbon::now()->subDays(6)->startOfDay();
        } elseif ($period === '30') {
            $start = Carbon::now()->subDays(29)->startOfDay();
        } elseif ($period === 'year') {
            $start = Carbon::now()->startOfYear()->startOfDay();
        } elseif ($period === 'all') {
            // Depuis toujours : date de début très ancienne (par exemple, 10 ans en arrière)
            // ou utiliser la date de création du compte utilisateur si disponible
            $start = Carbon::now()->subYears(10)->startOfDay();
        } else {
            // Default to 30 days
            $start = Carbon::now()->subDays(29)->startOfDay();
        }

        return [$start, $end];
    }

    /**
     * Get SQL group by clause based on date range
     * Compatible with both MySQL and PostgreSQL
     */
    private function getGroupBy(Carbon $start, Carbon $end): array
    {
        $daysDiff = $start->diffInDays($end);
        $driver = DB::connection()->getDriverName();

        if ($daysDiff <= 30) {
            // Group by day
            return [
                'select' => "DATE(created_at) as date, SUM(amount) as total",
                'group' => 'date',
                'order' => 'date'
            ];
        } elseif ($daysDiff <= 90) {
            // Group by week
            if ($driver === 'pgsql') {
                return [
                    'select' => "TO_CHAR(created_at, 'IYYY-IW') as date, SUM(amount) as total",
                    'group' => 'date',
                    'order' => 'date'
                ];
            } else {
                return [
                    'select' => "DATE_FORMAT(created_at, '%Y-%u') as date, SUM(amount) as total",
                    'group' => 'date',
                    'order' => 'date'
                ];
            }
        } else {
            // Group by month
            if ($driver === 'pgsql') {
                return [
                    'select' => "TO_CHAR(created_at, 'YYYY-MM') as date, SUM(amount) as total",
                    'group' => 'date',
                    'order' => 'date'
                ];
            } else {
                return [
                    'select' => "DATE_FORMAT(created_at, '%Y-%m') as date, SUM(amount) as total",
                    'group' => 'date',
                    'order' => 'date'
                ];
            }
        }
    }
}

