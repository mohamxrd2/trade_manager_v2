<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUuids; // Utiliser UUIDs pour la clé primaire

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'article_id',
        'variable_id',
        'name',
        'quantity',
        'amount',
        'sale_price',
        'type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Événement après création d'une transaction
        static::created(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });

        // Événement après mise à jour d'une transaction
        static::updated(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });

        // Événement avant suppression d'une transaction
        static::deleting(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });
    }

    /**
     * Update the user's wallet after transaction changes.
     */
    public function updateUserWallet(): void
    {
        if ($this->user_id) {
            $user = User::find($this->user_id);
            if ($user) {
                // Forcer le rechargement des attributs calculés
                $user->unsetRelation('transactions');
                $user->refresh();
            }
        }
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the article that owns the transaction.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /**
     * Get the variation that owns the transaction.
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variable_id');
    }

    /**
     * Get the remaining quantity of the variation.
     */
    public function getRemainingVariationQuantityAttribute(): int
    {
        if (!$this->variable_id) {
            return 0;
        }

        $variation = $this->variation;
        if (!$variation) {
            return 0;
        }

        // Calculer la quantité déjà vendue pour cette variation
        $soldQuantity = self::where('variable_id', $this->variable_id)
            ->where('type', 'sale')
            ->sum('quantity');

        return $variation->quantity - $soldQuantity;
    }
}
