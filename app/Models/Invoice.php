<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Invoice (Facture).
 */
class Invoice extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'client_id',
        'invoice_number',
        'status',
        'subtotal',
        'discount_amount',
        'discount_percent',
        'tax_amount',
        'tax_percent',
        'shipping_fee',
        'total',
        'billing_address',
        'shipping_address',
        'theme',
        'issued_at',
        'due_date',
        'paid_at',
        'notes',
        'terms',
        'pdf_path',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'is_overdue',
        'days_until_due',
        'formatted_total',
    ];

    /**
     * Get the user that owns the invoice.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the company that owns the invoice.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the client for the invoice.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the items for the invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Check if the invoice is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        try {
            if ($this->status === 'paid' || $this->status === 'cancelled') {
                return false;
            }
            
            if (!$this->due_date) {
                return false;
            }
            
            // S'assurer que due_date est bien un objet Carbon
            $dueDate = $this->due_date instanceof \Carbon\Carbon 
                ? $this->due_date 
                : \Carbon\Carbon::parse($this->due_date);
            
            return $dueDate->isPast();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the days until due date.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        try {
            if (!$this->due_date) {
                return null;
            }
            
            // S'assurer que due_date est bien un objet Carbon
            $dueDate = $this->due_date instanceof \Carbon\Carbon 
                ? $this->due_date 
                : \Carbon\Carbon::parse($this->due_date);
            
            return (int) now()->startOfDay()->diffInDays($dueDate, false);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the formatted total.
     */
    public function getFormattedTotalAttribute(): string
    {
        try {
            return number_format((float) ($this->total ?? 0), 2, ',', ' ') . ' €';
        } catch (\Exception $e) {
            return '0,00 €';
        }
    }

    /**
     * Generate the next invoice number for a user.
     */
    public static function generateInvoiceNumber(string $userId): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";
        
        // Trouver le dernier numéro de facture pour cet utilisateur cette année
        $lastInvoice = self::where('user_id', $userId)
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->first();
        
        if ($lastInvoice) {
            // Extraire le numéro et incrémenter
            $lastNumber = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . str_pad($newNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate totals for the invoice.
     */
    public function calculateTotals(): void
    {
        // Calculer le sous-total depuis les lignes
        $subtotal = $this->items()->sum('total_line');
        
        // Calculer la remise
        $discountAmount = $this->discount_percent > 0 
            ? $subtotal * ($this->discount_percent / 100) 
            : $this->discount_amount;
        
        $afterDiscount = $subtotal - $discountAmount;
        
        // Calculer la TVA
        $taxAmount = $this->tax_percent > 0 
            ? $afterDiscount * ($this->tax_percent / 100) 
            : $this->tax_amount;
        
        // Calculer le total
        $total = $afterDiscount + $taxAmount + $this->shipping_fee;
        
        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }
}

