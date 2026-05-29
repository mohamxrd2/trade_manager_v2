<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Client pour la facturation.
 */
class Client extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'payment_method',
        'billing_address',
        'shipping_address',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'invoices_count',
        'total_invoiced',
        'total_paid',
        'total_unpaid',
    ];

    /**
     * Get the user that owns the client.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoices for the client.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the invoices count attribute.
     */
    public function getInvoicesCountAttribute(): int
    {
        return $this->invoices()->count();
    }

    /**
     * Get the total invoiced amount.
     */
    public function getTotalInvoicedAttribute(): float
    {
        return (float) $this->invoices()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->sum('total');
    }

    /**
     * Get the total paid amount.
     */
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->invoices()
            ->where('status', 'paid')
            ->sum('total');
    }

    /**
     * Get the total unpaid amount.
     */
    public function getTotalUnpaidAttribute(): float
    {
        return (float) $this->invoices()
            ->where('status', 'unpaid')
            ->sum('total');
    }
}

