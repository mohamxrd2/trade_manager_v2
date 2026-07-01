<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle InvoiceItem (Ligne de facture).
 */
class InvoiceItem extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'invoice_id',
        'article_id',
        'name_snapshot',
        'description_snapshot',
        'unit_price',
        'quantity',
        'discount_percent',
        'discount_amount',
        'total_line',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_line' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the invoice that owns the item.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the article for the item.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Calculate the total for this line.
     */
    public static function calculateLineTotal(
        float $unitPrice, 
        int $quantity, 
        float $discountPercent = 0
    ): array {
        $subtotal = $unitPrice * $quantity;
        $discountAmount = $discountPercent > 0 
            ? $subtotal * ($discountPercent / 100) 
            : 0;
        $total = $subtotal - $discountAmount;
        
        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_line' => round($total, 2),
        ];
    }
}

