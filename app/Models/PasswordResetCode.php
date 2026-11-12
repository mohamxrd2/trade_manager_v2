<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de réinitialisation en cours (une seule par compte). Le code et le
 * jeton ne sont jamais stockés en clair : HMAC-SHA256 avec la clé de l'appli.
 */
class PasswordResetCode extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'code_hash',
        'attempts',
        'code_expires_at',
        'reset_token_hash',
        'reset_token_expires_at',
    ];

    protected $hidden = ['code_hash', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'code_expires_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}
