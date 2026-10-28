<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préférences d'affichage du ticket de caisse pour un compte — voir
 * ReceiptSettingController pour les valeurs par défaut appliquées quand
 * aucune ligne n'existe encore.
 */
class ReceiptSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'width',
        'show_logo',
        'show_address',
        'show_phone',
        'show_tax_id',
        'footer_message',
        'show_qr',
        'show_waelly_mention',
        'auto_print',
    ];

    protected $casts = [
        'show_logo' => 'boolean',
        'show_address' => 'boolean',
        'show_phone' => 'boolean',
        'show_tax_id' => 'boolean',
        'show_qr' => 'boolean',
        'show_waelly_mention' => 'boolean',
        'auto_print' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
