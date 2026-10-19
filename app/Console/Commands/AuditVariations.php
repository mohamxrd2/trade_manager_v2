<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Transaction;
use App\Models\InvoiceItem;
use Illuminate\Console\Command;

/**
 * READ-ONLY audit. Does not write anything to the database.
 * Run against the local database only — never production.
 */
class AuditVariations extends Command
{
    protected $signature = 'variations:audit';

    protected $description = 'Read-only audit of variable articles vs their variations (stock consistency, sales/invoice linkage)';

    public function handle(): int
    {
        $this->info('=== Audit des articles à variations (lecture seule) ===');
        $this->newLine();

        $variableArticles = Article::where('type', 'variable')
            ->withSum('variations', 'quantity')
            ->with('variations')
            ->get();

        $totalVariable = $variableArticles->count();
        $this->line("(a) Nombre total d'articles de type 'variable' : {$totalVariable}");
        $this->newLine();

        if ($totalVariable === 0) {
            $this->warn('Aucun article variable trouvé — rien de plus à auditer pour les variations.');
            $this->newLine();
        }

        if ($totalVariable > 0) {
        $consistent = [];
        $gap = [];
        $noVariation = [];

        foreach ($variableArticles as $article) {
            $variationCount = $article->variations->count();
            $sumVariations = (int) ($article->variations_sum_quantity ?? 0);

            if ($variationCount === 0) {
                $noVariation[] = $article;
                continue;
            }

            if ($sumVariations === (int) $article->quantity) {
                $consistent[] = $article;
            } elseif ($sumVariations < (int) $article->quantity) {
                $gap[] = [
                    'article' => $article,
                    'sum' => $sumVariations,
                    'article_quantity' => (int) $article->quantity,
                    'ecart' => (int) $article->quantity - $sumVariations,
                ];
            } else {
                // sum > article.quantity — should not normally happen given today's
                // "sum <= article.quantity" constraint in VariationController, but
                // report it in case stock was adjusted another way since.
                $gap[] = [
                    'article' => $article,
                    'sum' => $sumVariations,
                    'article_quantity' => (int) $article->quantity,
                    'ecart' => (int) $article->quantity - $sumVariations, // negative
                ];
            }
        }

        $this->line('(b) Articles cohérents — SUM(variations.quantity) = article.quantity : ' . count($consistent));
        foreach ($consistent as $article) {
            $this->line("    - {$article->name} (article_id={$article->id}, user_id={$article->user_id}) : quantite={$article->quantity}, variations=" . $article->variations->count());
        }
        $this->newLine();

        $this->line('(c) Articles avec écart — SUM(variations.quantity) != article.quantity : ' . count($gap));
        foreach ($gap as $row) {
            $article = $row['article'];
            $signe = $row['ecart'] >= 0 ? '+' : '';
            $this->line("    - {$article->name} (article_id={$article->id}, user_id={$article->user_id}) : article.quantity={$row['article_quantity']}, somme variations={$row['sum']}, écart={$signe}{$row['ecart']}" . ($row['ecart'] < 0 ? '  [ANOMALIE: somme > article.quantity]' : '  [stock non assigné à une variation]'));
        }
        $this->newLine();

        $this->line('(d) Articles variables SANS AUCUNE variation : ' . count($noVariation));
        foreach ($noVariation as $article) {
            $this->line("    - {$article->name} (article_id={$article->id}, user_id={$article->user_id}) : quantite actuelle={$article->quantity}, prix={$article->sale_price}");
        }
        $this->newLine();

        // (e) Sales and invoices linked to variable articles, with or without a variation specified.
        $variableArticleIds = $variableArticles->pluck('id');

        $salesWithVariation = Transaction::where('type', 'sale')
            ->whereIn('article_id', $variableArticleIds)
            ->whereNotNull('variable_id')
            ->count();

        $ordersWithoutVariation = Transaction::where('type', 'sale')
            ->whereIn('article_id', $variableArticleIds)
            ->whereNull('variable_id')
            ->get(['id', 'user_id', 'article_id', 'quantity', 'invoice_id']);

        $this->line('(e) Ventes (transactions type=sale) liées à des articles variables :');
        $this->line("    - avec variation précisée (variable_id renseigné) : {$salesWithVariation}");
        $this->line("    - SANS variation précisée (variable_id NULL)      : {$ordersWithoutVariation->count()}");
        foreach ($ordersWithoutVariation as $tx) {
            $this->line("        tx={$tx->id} user_id={$tx->user_id} article_id={$tx->article_id} quantity={$tx->quantity}" . ($tx->invoice_id ? " invoice_id={$tx->invoice_id}" : ''));
        }
        $this->newLine();

        // invoice_items.variation_id now exists (migration appliquée) — distinguer avec/sans variation.
        $itemsWithVariation = InvoiceItem::whereIn('article_id', $variableArticleIds)->whereNotNull('variation_id')->count();
        $itemsWithoutVariation = InvoiceItem::whereIn('article_id', $variableArticleIds)->whereNull('variation_id')->get(['id', 'invoice_id', 'article_id', 'quantity']);

        $this->line("    - lignes de facture AVEC variation précisée : {$itemsWithVariation}");
        $this->line("    - lignes de facture SANS variation précisée : {$itemsWithoutVariation->count()}");
        foreach ($itemsWithoutVariation as $item) {
            $this->line("        item={$item->id} invoice_id={$item->invoice_id} article_id={$item->article_id} quantity={$item->quantity}");
        }
        $this->newLine();
        } // fin du bloc "if ($totalVariable > 0)"

        // (f) Articles SIMPLES — mesure (lecture seule, aucune correction ici)
        // de la dérive historique causée par l'ancien bug update()/destroy()
        // de TransactionController qui incrémentait/décrémentait
        // article.quantity à chaque édition/suppression d'une vente.
        $simpleArticles = Article::where('type', 'simple')
            ->withSum(['transactions' => function ($q) {
                $q->where('type', 'sale');
            }], 'quantity')
            ->get();

        $this->line("(f) Articles SIMPLES : {$simpleArticles->count()}");
        $negativeSimple = [];
        foreach ($simpleArticles as $article) {
            $sold = (int) ($article->transactions_sum_quantity ?? 0);
            $remaining = (int) $article->quantity - $sold;
            $this->line("    - {$article->name} (article_id={$article->id}, user_id={$article->user_id}) : quantity={$article->quantity}, vendu={$sold}, restant={$remaining}");
            if ($remaining < 0) {
                $negativeSimple[] = ['article' => $article, 'remaining' => $remaining];
            }
        }
        $this->newLine();

        $this->line('    Restants négatifs (dérive historique) : ' . count($negativeSimple));
        foreach ($negativeSimple as $row) {
            $this->line("        - {$row['article']->name} (article_id={$row['article']->id}) : restant={$row['remaining']}");
        }
        $this->newLine();

        $this->info('=== Fin de l\'audit (lecture seule, aucune donnée modifiée) ===');

        return self::SUCCESS;
    }
}
