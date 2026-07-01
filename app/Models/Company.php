<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
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
        'sector',
        'headquarters',
        'email',
        'legal_status',
        'bank_account_number',
        'logo',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'is_invoice_ready',
        'logo_url',
    ];

    /**
     * Get the user that owns the company.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoices for the company.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Check if company is ready for invoicing.
     * Requires email and logo to be set.
     */
    public function getIsInvoiceReadyAttribute(): bool
    {
        return !empty($this->email) && !empty($this->logo);
    }

    /**
     * Get missing fields for invoicing.
     */
    public function getMissingInvoiceFields(): array
    {
        $missing = [];
        
        if (empty($this->email)) {
            $missing[] = 'email';
        }
        
        if (empty($this->logo)) {
            $missing[] = 'logo';
        }
        
        return $missing;
    }

    /**
     * Get the full URL for the company logo.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (empty($this->logo)) {
            return null;
        }

        return asset('storage/' . $this->logo);
    }
}
