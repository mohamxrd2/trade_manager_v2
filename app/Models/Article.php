<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'sale_price',
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
     */
    public function getLowStockAttribute(): bool
    {
        return $this->sales_percentage > 80;
    }

    /**
     * Get the stock value attribute.
     */
    public function getStockValueAttribute(): float
    {
        return $this->remaining_quantity * $this->sale_price;
    }
}
