<?php

namespace App\Models;

use App\Services\ArticleStockService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Article extends Model
{
    use HasUuids;

    /**
     * For a variable article, quantity/sale_price/price_max are a cache
     * synced from its variations (ArticleStockService::syncVariableTotals).
     * Block any other direct write to those columns on an UPDATE (initial
     * creation with placeholder zeros, before variations exist, is allowed —
     * that's the atomic-creation flow's own responsibility, not a bypass).
     */
    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            if ($article->type !== 'variable' || !$article->exists || ArticleStockService::isSyncing()) {
                return;
            }

            $guardedFields = array_intersect(['quantity', 'sale_price', 'price_max'], array_keys($article->getDirty()));

            if (empty($guardedFields)) {
                return;
            }

            $message = 'Écriture directe interdite sur [' . implode(', ', $guardedFields) .
                "] pour l'article variable {$article->id} — passez par ArticleStockService::syncVariableTotals().";

            if (app()->environment(['local', 'testing'])) {
                throw new \RuntimeException($message);
            }

            Log::error($message, ['article_id' => $article->id, 'dirty' => $guardedFields]);
        });
    }


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'sale_price',
        'price_max',
        'quantity',
        'type',
        'image',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sale_price' => 'decimal:2',
        'price_max' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'sold_quantity',
        'remaining_quantity',
        'sales_percentage',
        'low_stock',
        'stock_value',
    ];

    /**
     * Get the user that owns the article.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions for the article.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'article_id');
    }

    /**
     * Get the variations for the article.
     */
    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    /**
     * Get the stock replenishments history for the article.
     */
    public function stockReplenishments(): HasMany
    {
        return $this->hasMany(StockReplenishment::class);
    }

    /**
     * Get the sold quantity attribute.
     */
    public function getSoldQuantityAttribute(): int
    {
        // Utiliser la valeur pré-chargée si disponible, sinon calculer
        if (isset($this->transactions_sum_quantity)) {
            return $this->transactions_sum_quantity;
        }
        
        // Calculer directement depuis les transactions
        return $this->transactions()->where('type', 'sale')->sum('quantity');
    }

    /**
     * Get the remaining quantity attribute.
     */
    public function getRemainingQuantityAttribute(): int
    {
        return $this->quantity - $this->sold_quantity;
    }

    /**
     * Get the sales percentage attribute.
     */
    public function getSalesPercentageAttribute(): float
    {
        if ($this->quantity == 0) {
            return 0;
        }
        
        return round(($this->sold_quantity * 100) / $this->quantity, 2);
    }

    /**
     * Get the low stock attribute.
     * 
     * Un article est considéré en stock faible si son sales_percentage est supérieur ou égal
     * au seuil personnalisé défini dans les paramètres utilisateur (user_settings.low_stock_threshold).
     * Par défaut, le seuil est de 80%.
     */
    public function getLowStockAttribute(): bool
    {
        // Récupérer le seuil personnalisé depuis les paramètres utilisateur
        // Charger la relation user.settings si elle n'est pas déjà chargée
        if (!$this->relationLoaded('user')) {
            $this->load('user.settings');
        }
        
        $lowStockThreshold = $this->user?->settings?->low_stock_threshold ?? 80;
        
        return $this->sales_percentage >= $lowStockThreshold;
    }

    /**
     * Get the stock value attribute.
     */
    public function getStockValueAttribute(): float
    {
        return $this->remaining_quantity * $this->sale_price;
    }
}
