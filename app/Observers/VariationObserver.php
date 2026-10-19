<?php

namespace App\Observers;

use App\Models\Variation;
use App\Services\ArticleStockService;

class VariationObserver
{
    public function created(Variation $variation): void
    {
        $this->sync($variation);
    }

    public function updated(Variation $variation): void
    {
        $this->sync($variation);
    }

    public function deleted(Variation $variation): void
    {
        $this->sync($variation);
    }

    public function restored(Variation $variation): void
    {
        $this->sync($variation);
    }

    private function sync(Variation $variation): void
    {
        $article = $variation->article;

        if ($article) {
            ArticleStockService::syncVariableTotals($article);
        }
    }
}
