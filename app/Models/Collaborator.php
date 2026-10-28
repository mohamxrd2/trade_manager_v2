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
        'user_id_member',
        'name',
        'phone',
        'email',
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
     * Le compte d'accès lié à CET associé, s'il en a un (chantier "Équipe"
     * étape 2 — un associé peut AUSSI être membre, les deux notions sont
     * indépendantes). Null si l'associé n'a aucun accès applicatif.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_member');
    }

    /**
     * Get the calculated wallet attribute.
     * Calculé comme : balance * (part / 100).
     *
     * Base = `balance` (ventes − dépenses + apports − retraits), pas
     * seulement ventes − dépenses — décision métier du 26/10/2026, même
     * règle que User::getWalletAttribute() (montant du gérant) : un apport
     * augmente la part de CE collaborateur, un retrait la diminue.
     */
    public function getWalletAttribute(): float
    {
        $user = $this->user;
        if (!$user) {
            return 0.0;
        }

        return (float) bcmul($user->balance, bcdiv($this->part, 100, 4), 2);
    }
}
