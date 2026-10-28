<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En-tête d'une opération (panier) — code, type, moyen de paiement, total,
 * auteur, client, date. Les LIGNES (article, variation, quantité, montant)
 * restent sur Transaction (voir Transaction::operation()) : une Operation a
 * une ou plusieurs Transaction via operation_id.
 */
class Operation extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'type',
        'code',
        'payment_method',
        'total_amount',
        'amount_received',
        'change_given',
        'client_id',
        'invoice_id',
        'note',
        'occurred_at',
        'idempotency_key',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'change_given' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /**
     * Les lignes de cette opération.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Le compte (l'entreprise) propriétaire de l'opération.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'auteur réel de l'opération — prévu pour les futurs membres
     * d'équipe (nullable aujourd'hui, toujours le gérant en pratique).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * "Caissier : {nom}" du ticket de caisse — PAS dans $appends (coûterait
     * une requête par ligne sur GET /api/operations, qui n'a pas besoin de
     * cette info) : ajouté explicitement via append('author_name') par
     * OperationController::loadOperationForResponse(), utilisé seulement
     * par les réponses à une seule opération (show/store).
     */
    public function getAuthorNameAttribute(): ?string
    {
        $author = $this->createdBy ?: $this->user;
        if (!$author) {
            return null;
        }

        $name = trim(($author->first_name ?? '') . ' ' . ($author->last_name ?? ''));

        return $name !== '' ? $name : ($author->username ?? null);
    }
}
