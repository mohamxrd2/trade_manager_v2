<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajustement d'UN membre par rapport aux permissions par défaut de son
 * rôle — `granted=true` ajoute une permission non couverte par le rôle,
 * `granted=false` en retire une. Voir App\Support\Permissions::
 * effectivePermissions() pour la résolution (rôle ± ajustements).
 */
class MemberPermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'permission',
        'granted',
    ];

    protected $casts = [
        'granted' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
