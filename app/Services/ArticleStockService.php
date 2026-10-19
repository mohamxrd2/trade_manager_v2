<?php

namespace App\Services;

use App\Models\Article;

class ArticleStockService
{
    /**
     * True while syncVariableTotals() is writing to an article — lets the
     * Article model's saving guard tell a trusted sync write apart from any
     * other code trying to set quantity/sale_price/price_max directly.
     */
    private static bool $syncing = false;

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /**
     * Single source of truth for a variable article's cache columns.
     * Recomputes quantity = SUM(variations.quantity), sale_price = MIN(variations.price),
     * price_max = MAX(variations.price), using only non-archived variations
     * (Variation uses SoftDeletes, so the default query already excludes them).
     *
     * No-op for simple articles — their quantity/sale_price are entered directly.
     */
    public static function syncVariableTotals(Article $article): Article
    {
        if ($article->type !== 'variable') {
            return $article;
        }

        $variations = $article->variations()->get();
        $pricedVariations = $variations->filter(fn ($v) => $v->price !== null);

        self::$syncing = true;
        try {
            $article->quantity = (int) $variations->sum('quantity');

            if ($variations->isEmpty()) {
                $article->sale_price = 0;
                $article->price_max = null;
            } elseif ($pricedVariations->isNotEmpty()) {
                $article->sale_price = $pricedVariations->min('price');
                $article->price_max = $pricedVariations->max('price');
            }
            // Sinon (des variations existent mais aucune n'a encore de prix —
            // état transitoire propre au backfill de données héritées) : on ne
            // touche pas sale_price/price_max, pour ne jamais écrire NULL sur
            // une colonne NOT NULL ni effacer un prix d'affichage existant.

            $article->save();
        } finally {
            self::$syncing = false;
        }

        return $article->refresh();
    }
}
