<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fixes negative remaining stock (more was sold than was ever stocked in).
 *
 * For a SIMPLE article: sets quantity = sold_quantity (remaining becomes 0),
 * same behaviour as before.
 *
 * For a VARIABLE article: fixes it PER VARIATION (sets each negative-remaining
 * variation's quantity = its own sold_quantity), then resyncs the article's
 * cache via ArticleStockService::syncVariableTotals() — never touches
 * article.quantity directly, which would desync it from its variations.
 *
 * Default is --dry-run (report only). Pass --force to apply.
 */
class FixNegativeStock extends Command
{
    protected $signature = 'stock:fix-negative {--force : Actually persist the changes (default is dry-run)}';

    protected $description = 'Fix articles/variations with negative remaining quantities by adjusting stock quantities';

    public function handle()
    {
        $force = (bool) $this->option('force');

        $this->info($force ? '🔧 Correction du stock négatif (APPLICATION RÉELLE)' : '🔍 Correction du stock négatif (--dry-run, rien ne sera écrit)');
        $this->newLine();

        $fixedSimple = 0;
        $fixedVariations = 0;
        $skippedVariableArticles = 0;

        DB::transaction(function () use ($force, &$fixedSimple, &$fixedVariations, &$skippedVariableArticles) {
            // --- Articles simples ---
            $simpleArticles = Article::where('type', 'simple')
                ->withSum(['transactions' => function ($q) {
                    $q->where('type', 'sale');
                }], 'quantity')
                ->get();

            $negativeSimple = $simpleArticles->filter(function ($article) {
                return ((int) $article->quantity - (int) $article->transactions_sum_quantity) < 0;
            });

            if ($negativeSimple->isEmpty()) {
                $this->info('✅ Aucun article simple avec quantité négative trouvé.');
            } else {
                $this->warn("⚠️  {$negativeSimple->count()} article(s) simple(s) en stock négatif :");
                foreach ($negativeSimple as $article) {
                    $sold = (int) ($article->transactions_sum_quantity ?? 0);
                    $remaining = (int) $article->quantity - $sold;

                    $this->line("📦 {$article->name} (ID: {$article->id})");
                    $this->line("   Stock actuel: {$article->quantity} | Vendu: {$sold} | Restant: {$remaining}");
                    $this->line("   → quantity ajustée à {$sold} (restant devient 0)");

                    if ($force) {
                        $article->update(['quantity' => $sold]);
                    }
                    $fixedSimple++;
                }
            }

            $this->newLine();

            // --- Articles variables : correction VARIATION PAR VARIATION ---
            $variableArticles = Article::where('type', 'variable')->with('variations')->get();
            $touchedAnyVariable = false;

            foreach ($variableArticles as $article) {
                $negativeVariations = $article->variations->filter(function (Variation $variation) {
                    return $variation->remaining_quantity < 0;
                });

                if ($negativeVariations->isEmpty()) {
                    continue;
                }

                $touchedAnyVariable = true;
                $this->warn("⚠️  Article variable « {$article->name} » (ID: {$article->id}) — variation(s) en stock négatif :");

                foreach ($negativeVariations as $variation) {
                    $sold = $variation->sold_quantity;
                    $remaining = $variation->remaining_quantity;

                    $this->line("   · {$variation->name} : quantity={$variation->quantity}, vendu={$sold}, restant={$remaining}");
                    $this->line("     → quantity ajustée à {$sold} (jamais article.quantity directement)");

                    if ($force) {
                        // L'observer de Variation resynchronise automatiquement
                        // l'article après ce save (ArticleStockService).
                        $variation->update(['quantity' => $sold]);
                    }
                    $fixedVariations++;
                }

                if (!$force) {
                    // Dry-run : afficher ce que serait le nouveau total article
                    // sans l'écrire.
                    $simulatedSum = $article->variations->sum(function (Variation $v) use ($negativeVariations) {
                        return $negativeVariations->contains('id', $v->id) ? $v->sold_quantity : $v->quantity;
                    });
                    $this->line("   → article.quantity serait resynchronisé à {$simulatedSum} (SUM des variations)");
                } else {
                    ArticleStockService::syncVariableTotals($article->fresh());
                    $this->line("   → article.quantity resynchronisé à {$article->fresh()->quantity}");
                }

                $this->newLine();
            }

            if (!$touchedAnyVariable) {
                $this->info('✅ Aucune variation en stock négatif trouvée.');
            }
        });

        $this->newLine();
        $this->info('=== Résumé ===');
        $this->line("Articles simples corrigés : {$fixedSimple}");
        $this->line("Variations corrigées : {$fixedVariations}");

        if (!$force) {
            $this->newLine();
            $this->warn('Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');
        } else {
            $this->info('🎉 Correction terminée !');
        }

        return self::SUCCESS;
    }
}
