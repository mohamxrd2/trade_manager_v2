<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collaborator extends Model
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
        'phone',
        'part',
        'image',
    ];

    /**
     * The attributes that should be guarded (not mass assignable).
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id', // UUID généré automatiquement
    ];

    /**
     * The attributes that should be immutable (cannot be changed after creation).
     *
     * @var array<int, string>
     */
    protected $immutable = [
        'part', // La part ne peut pas être modifiée après création
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'part' => 'decimal:2',
    ];

    /**
     * Get the user that owns the collaborator.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the calculated wallet attribute.
     * Calculé comme : calculated_wallet * (part / 100)
     */
    public function getWalletAttribute(): float
    {
        $user = $this->user;
        if (!$user) {
            return 0.0;
        }

        // Calculer le wallet de l'utilisateur
        $totalSale = $user->transactions()->where('type', 'sale')->sum('amount') ?? 0;
        $totalExpense = $user->transactions()->where('type', 'expense')->sum('amount') ?? 0;
        $calculatedWallet = $totalSale - $totalExpense;

        // Calculer la part du collaborateur
        return (float) bcmul($calculatedWallet, bcdiv($this->part, 100, 4), 2);
    }
}
