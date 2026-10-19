<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill for the variable-article overhaul.
 *
 * Processes each variable article strictly by its own id (never groups or
 * joins by name — two different users can own an article with the same
 * name). Everything runs inside a single DB transaction; if the
 * remaining-quantity invariant (remaining BEFORE == sum of variation
 * remaining AFTER) fails for any single article not explicitly trusted via
 * --trust-variations, the whole run is rolled back and the offending
 * article is named.
 *
 * Default is --dry-run (nothing persisted). Pass --force to commit.
 */
class BackfillVariations extends Command
{
    protected $signature = 'variations:backfill
        {--force : Actually persist the changes (default is dry-run)}
        {--trust-variations= : Comma-separated article_id list explicitly allowed to use resync-only mode (quantity=0 seeder artifacts where variations are the trusted source of truth)}';

    protected $description = 'Backfill price on variations, reconcile unassigned stock into a "Sans précision" variation, and resync article totals';

    private const UNASSIGNED_NAME = 'Sans précision';
    private const STANDARD_NAME = 'Standard';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $trustList = array_filter(array_map('trim', explode(',', (string) $this->option('trust-variations'))));

        $this->info($force ? '=== Backfill variations (APPLICATION RÉELLE) ===' : '=== Backfill variations (--dry-run, rien ne sera écrit) ===');
        if (!empty($trustList)) {
            $this->line('--trust-variations actif pour : ' . implode(', ', $trustList));
        }
        $this->newLine();

        $summary = [
            'articles_processed' => 0,
            'variations_price_backfilled' => 0,
            'unassigned_variations_created' => 0,
            'standard_variations_created' => 0,
            'orphan_sales_reattached' => 0,
            'orphan_invoice_items_reattached' => 0,
            'remaining_before_total' => 0,
            'remaining_after_total' => 0,
            'bypass_count' => 0,
            'negative_stock_count' => 0,
        ];

        $bypassReport = [];
        $negativeStockReport = [];
        $failedArticle = null;

        try {
            DB::transaction(function () use (&$summary, &$failedArticle, &$bypassReport, &$negativeStockReport, $trustList) {
                Article::where('type', 'variable')
                    ->chunkById(200, function ($articles) use (&$summary, &$failedArticle, &$bypassReport, &$negativeStockReport, $trustList) {
                        foreach ($articles as $article) {
                            $failedArticle = $article->name . ' (' . $article->id . ')';
                            $this->processArticle($article, $summary, $trustList, $bypassReport, $negativeStockReport);
                        }
                    });

                if ($summary['remaining_before_total'] !== $summary['remaining_after_total']) {
                    $failedArticle = 'TOTAL_MISMATCH';
                    throw new \RuntimeException('Invariant global violé (totaux avant/après différents, hors bypass)');
                }

                if (!$this->option('force')) {
                    throw new DryRunAbort();
                }
            });
        } catch (DryRunAbort) {
            // Expected in dry-run mode — the transaction was rolled back on purpose.
        } catch (\Throwable $e) {
            $this->error('ÉCHEC — rollback complet effectué. Raison : ' . $e->getMessage());
            if ($failedArticle) {
                $this->error('Dernier article en cours de traitement : ' . $failedArticle);
            }
            return self::FAILURE;
        }

        if (!empty($bypassReport)) {
            $this->newLine();
            $this->warn('=== BYPASS – resync only (--trust-variations) ===');
            foreach ($bypassReport as $row) {
                $this->line("- {$row['name']} (article_id={$row['id']}, user_id={$row['user_id']})");
                $this->line("    Motif : {$row['reason']}");
                $this->line("    Avant : quantity={$row['qty_before']}, restant={$row['remaining_before']}");
                $this->line("    Après : quantity={$row['qty_after']}, restant={$row['remaining_after']}");
            }
        }

        if (!empty($negativeStockReport)) {
            $this->newLine();
            $this->warn('=== Stock négatif existant (hors périmètre — non modifié) ===');
            foreach ($negativeStockReport as $row) {
                $this->line("- {$row['name']} (article_id={$row['id']}, user_id={$row['user_id']}) : restant total={$row['remaining']}");
                foreach ($row['variations'] as $v) {
                    $this->line("    · {$v['name']} : restant={$v['remaining']}");
                }
            }
        }

        $this->newLine();
        $this->info('=== Résumé ===');
        $this->line("Articles variables traités : {$summary['articles_processed']}");
        $this->line("Variations — prix backfillé : {$summary['variations_price_backfilled']}");
        $this->line("Variations « Sans précision » créées : {$summary['unassigned_variations_created']}");
        $this->line("Variations « Standard » créées (article sans aucune variation) : {$summary['standard_variations_created']}");
        $this->line("Ventes orphelines rattachées : {$summary['orphan_sales_reattached']}");
        $this->line("Lignes de facture orphelines rattachées : {$summary['orphan_invoice_items_reattached']}");
        $this->line("Articles en bypass (resync only) : {$summary['bypass_count']}");
        $this->line("Articles avec stock négatif préexistant (signalés, non modifiés) : {$summary['negative_stock_count']}");
        $this->line("Restant total AVANT (hors bypass) : {$summary['remaining_before_total']}");
        $this->line("Restant total APRÈS (hors bypass) : {$summary['remaining_after_total']}");
        $this->line($summary['remaining_before_total'] === $summary['remaining_after_total'] ? '✅ Totaux identiques (hors bypass explicite).' : '❌ TOTAUX DIFFÉRENTS — ne devrait jamais arriver ici (aurait dû rollback).');

        if (!$force) {
            $this->newLine();
            $this->warn('Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');
        }

        return self::SUCCESS;
    }

    private function processArticle(
        Article $article,
        array &$summary,
        array $trustList,
        array &$bypassReport,
        array &$negativeStockReport
    ): void {
        $summary['articles_processed']++;

        $totalSold = Transaction::where('article_id', $article->id)
            ->where('type', 'sale')
            ->sum('quantity');

        $remainingBefore = (int) $article->quantity - (int) $totalSold;

        $this->line("--- {$article->name} (article_id={$article->id}, user_id={$article->user_id}) ---");
        $this->line("    Restant AVANT : {$remainingBefore}  (article.quantity={$article->quantity}, vendu total={$totalSold})");

        $variations = Variation::where('article_id', $article->id)->get();
        $quantitiesBeforeAnyWrite = $variations->pluck('quantity', 'id');

        // (1) Backfill price on every variation missing one.
        foreach ($variations as $variation) {
            if ($variation->price === null) {
                $lastPracticedPrice = Transaction::where('variable_id', $variation->id)
                    ->where('type', 'sale')
                    ->whereNotNull('sale_price')
                    ->orderByDesc('created_at')
                    ->value('sale_price');

                $variation->price = $article->sale_price;
                $variation->save();
                $summary['variations_price_backfilled']++;

                $this->line("    - prix de '{$variation->name}' fixé à {$article->sale_price}" .
                    ($lastPracticedPrice !== null ? " (dernier prix réellement pratiqué sur cette variation : {$lastPracticedPrice})" : ' (aucune vente antérieure sur cette variation)'));
            }
        }

        if ($variations->isEmpty()) {
            $standard = Variation::create([
                'article_id' => $article->id,
                'name' => self::STANDARD_NAME,
                'quantity' => $article->quantity,
                'price' => $article->sale_price,
            ]);
            $summary['standard_variations_created']++;
            $this->line('    - création de \'' . self::STANDARD_NAME . "' : quantity={$article->quantity}, price={$article->sale_price}");
            $variations = collect([$standard]);
            $quantitiesBeforeAnyWrite->put($standard->id, $standard->quantity);
        }

        $namedSum = (int) Variation::where('article_id', $article->id)->sum('quantity');
        $gap = (int) $article->quantity - $namedSum;

        $orphanSales = Transaction::where('article_id', $article->id)
            ->where('type', 'sale')
            ->whereNull('variable_id')
            ->get();
        $orphanSoldQty = (int) $orphanSales->sum('quantity');

        $orphanInvoiceItems = InvoiceItem::where('article_id', $article->id)
            ->whereNull('variation_id')
            ->get();

        $isAnomalyProfile = (int) $article->quantity === 0
            && $namedSum > 0
            && $orphanSales->isEmpty()
            && $orphanInvoiceItems->isEmpty();

        if ($isAnomalyProfile) {
            $this->handleBypassCandidate(
                $article,
                $remainingBefore,
                $namedSum,
                $quantitiesBeforeAnyWrite,
                $trustList,
                $summary,
                $bypassReport
            );
            $this->newLine();
            return;
        }

        $unassigned = null;

        if ($gap > 0 || $orphanSoldQty > 0 || $orphanInvoiceItems->isNotEmpty()) {
            // quantity("Sans précision") MUST equal the gap exactly — not
            // max(gap, orphanSoldQty). A negative remaining on this bucket is
            // valid and expected (same as any oversold variation, reported in
            // the "stock négatif" section), and is the only way the remaining
            // invariant (before == after) holds in every case. See the
            // TEST_Orphelin regression this fixed: gap=0 with a 3-unit orphan
            // sale — using max() would have inflated remaining_after by +3.
            $unassignedQty = $gap;

            if ($gap < 0) {
                throw new \RuntimeException(
                    "Article {$article->name} (article_id={$article->id}) : écart négatif (gap={$gap}) hors du profil anomalie (quantity != 0) — cas non géré automatiquement, à examiner manuellement."
                );
            }

            $unassigned = Variation::where('article_id', $article->id)
                ->where('name', self::UNASSIGNED_NAME)
                ->first();

            if (!$unassigned) {
                $unassigned = Variation::create([
                    'article_id' => $article->id,
                    'name' => self::UNASSIGNED_NAME,
                    'quantity' => $unassignedQty,
                    'price' => $article->sale_price,
                ]);
                $summary['unassigned_variations_created']++;
                $this->line("    - création de '" . self::UNASSIGNED_NAME . "' : quantity={$unassignedQty} (écart={$gap}, ventes sans variation={$orphanSoldQty})");
            } elseif ((int) $unassigned->quantity !== $unassignedQty) {
                $unassigned->quantity = $unassignedQty;
                $unassigned->save();
            }

            if ($unassigned && $orphanSales->isNotEmpty()) {
                Transaction::whereIn('id', $orphanSales->pluck('id'))->update(['variable_id' => $unassigned->id]);
                $summary['orphan_sales_reattached'] += $orphanSales->count();
                $this->line("    - rattachement de {$orphanSales->count()} vente(s) orpheline(s) à '" . self::UNASSIGNED_NAME . "'");
            }

            if ($unassigned && $orphanInvoiceItems->isNotEmpty()) {
                InvoiceItem::whereIn('id', $orphanInvoiceItems->pluck('id'))->update(['variation_id' => $unassigned->id]);
                $summary['orphan_invoice_items_reattached'] += $orphanInvoiceItems->count();
                $this->line("    - rattachement de {$orphanInvoiceItems->count()} ligne(s) de facture orpheline(s) à '" . self::UNASSIGNED_NAME . "'");
            }
        }

        $finalVariations = Variation::where('article_id', $article->id)->get();
        $article = ArticleStockService::syncVariableTotals($article);
        $newQuantity = $article->quantity;
        $newMinPrice = $finalVariations->min('price');
        $newMaxPrice = $finalVariations->max('price');

        $remainingAfter = (int) $finalVariations->sum(function (Variation $v) {
            $sold = Transaction::where('variable_id', $v->id)->where('type', 'sale')->sum('quantity');
            return (int) $v->quantity - (int) $sold;
        });

        $this->line("    Restant APRÈS : {$remainingAfter}  (article.quantity recalculé={$newQuantity}, prix {$newMinPrice}" . ($newMaxPrice != $newMinPrice ? " – {$newMaxPrice}" : '') . ')');

        if ($remainingAfter < 0) {
            $summary['negative_stock_count']++;
            $negativeStockReport[] = [
                'name' => $article->name,
                'id' => $article->id,
                'user_id' => $article->user_id,
                'remaining' => $remainingAfter,
                'variations' => $finalVariations->map(function (Variation $v) {
                    $sold = Transaction::where('variable_id', $v->id)->where('type', 'sale')->sum('quantity');
                    return ['name' => $v->name, 'remaining' => (int) $v->quantity - (int) $sold];
                })->all(),
            ];
            $this->line('    ⚠️ Stock négatif préexistant — signalé, non corrigé par ce backfill (hors périmètre).');
        }

        if ($remainingAfter !== $remainingBefore) {
            throw new \RuntimeException("Invariant violé pour {$article->name} (article_id={$article->id}) : restant avant={$remainingBefore}, restant après={$remainingAfter}");
        }

        $summary['remaining_before_total'] += $remainingBefore;
        $summary['remaining_after_total'] += $remainingAfter;

        $this->line('    ✅ OK (restant avant = restant après)');
        $this->newLine();
    }

    private function handleBypassCandidate(
        Article $article,
        int $remainingBefore,
        int $namedSum,
        $quantitiesBeforeAnyWrite,
        array $trustList,
        array &$summary,
        array &$bypassReport
    ): void {
        if (!in_array($article->id, $trustList, true)) {
            $this->line("    [CANDIDAT à --trust-variations] article.quantity=0 mais SUM(variations)={$namedSum}, aucune vente/ligne de facture orpheline.");
            $this->line('    Non inclus dans --trust-variations → rollback complet de la commande.');
            throw new \RuntimeException("Article {$article->name} (article_id={$article->id}) correspond au profil anomalie (quantity=0, variations non vides) mais n'est pas listé dans --trust-variations.");
        }

        if (app()->environment('production')) {
            $confirmed = $this->confirm(
                "BYPASS resync-only pour '{$article->name}' (user_id={$article->user_id}) : " .
                "quantity {$article->quantity} -> {$namedSum}, restant {$remainingBefore} -> " . ($namedSum - 0) . '. Confirmer ?'
            );
            if (!$confirmed) {
                throw new \RuntimeException("BYPASS refusé en confirmation interactive pour {$article->name} (article_id={$article->id}).");
            }
        }

        // Re-check: still zero orphan sales/invoice-items at the moment of bypass (belt and suspenders).
        $stillOrphanSales = Transaction::where('article_id', $article->id)->where('type', 'sale')->whereNull('variable_id')->exists();
        $stillOrphanItems = InvoiceItem::where('article_id', $article->id)->whereNull('variation_id')->exists();
        if ($stillOrphanSales || $stillOrphanItems) {
            throw new \RuntimeException("BYPASS invalide pour {$article->name} (article_id={$article->id}) : vente ou ligne de facture sans variation détectée.");
        }

        $finalVariations = Variation::where('article_id', $article->id)->get();

        // Guard: this bypass path must NEVER touch a variation's quantity, only price/article cache.
        foreach ($finalVariations as $v) {
            $before = $quantitiesBeforeAnyWrite->get($v->id);
            if ($before !== null && (int) $before !== (int) $v->quantity) {
                throw new \RuntimeException("BYPASS invalide pour {$article->name} (article_id={$article->id}) : quantity de la variation '{$v->name}' modifiée.");
            }
        }

        $expectedQuantity = (int) $finalVariations->sum('quantity');
        $article = ArticleStockService::syncVariableTotals($article);
        $newQuantity = $article->quantity;

        $remainingAfter = (int) $finalVariations->sum(function (Variation $v) {
            $sold = Transaction::where('variable_id', $v->id)->where('type', 'sale')->sum('quantity');
            return (int) $v->quantity - (int) $sold;
        });

        // Checks required by the user, enforced explicitly (not just "by construction"):
        if ($newQuantity !== $expectedQuantity) {
            throw new \RuntimeException("BYPASS invalide pour {$article->name} : quantity après != SUM(variations.quantity).");
        }

        $this->line("    [BYPASS resync-only] quantity seeder=0 -> {$newQuantity} (SUM variations), restant {$remainingBefore} -> {$remainingAfter}");

        $summary['bypass_count']++;
        $bypassReport[] = [
            'name' => $article->name,
            'id' => $article->id,
            'user_id' => $article->user_id,
            'reason' => 'quantity seeder = 0',
            'qty_before' => 0,
            'qty_after' => $newQuantity,
            'remaining_before' => $remainingBefore,
            'remaining_after' => $remainingAfter,
        ];
    }
}

class DryRunAbort extends \Exception
{
}
