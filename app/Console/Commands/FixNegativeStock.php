<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixNegativeStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:fix-negative';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix articles with negative remaining quantities by adjusting stock quantities';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Recherche des articles avec des quantités négatives...');

        $articles = Article::withSum(['transactions' => function ($query) {
            $query->where('type', 'sale');
        }], 'quantity')->get();

        $negativeArticles = $articles->filter(function ($article) {
            $remainingQuantity = $article->quantity - $article->transactions_sum_quantity;
            return $remainingQuantity < 0;
        });

        if ($negativeArticles->isEmpty()) {
            $this->info('✅ Aucun article avec quantité négative trouvé.');
            return;
        }

        $this->warn("⚠️  Trouvé {$negativeArticles->count()} article(s) avec quantité négative :");

        foreach ($negativeArticles as $article) {
            $soldQuantity = $article->transactions_sum_quantity ?? 0;
            $currentRemaining = $article->quantity - $soldQuantity;
            
            $this->line("📦 {$article->name} (ID: {$article->id})");
            $this->line("   Stock actuel: {$article->quantity}");
            $this->line("   Quantité vendue: {$soldQuantity}");
            $this->line("   Quantité restante: {$currentRemaining}");
            
            // Ajuster la quantité en stock pour qu'elle soit égale aux ventes
            $newQuantity = $soldQuantity;
            $article->update(['quantity' => $newQuantity]);
            
            $this->info("   ✅ Quantité ajustée à: {$newQuantity}");
        }

        $this->info('🎉 Correction terminée !');
    }
}