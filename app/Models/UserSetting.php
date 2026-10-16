<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'currency',
        'low_stock_threshold',
        'language',
        'notify_stock_low',
        'notify_invoice_paid',
        'notify_invoice_overdue',
        'notify_team_joined',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'low_stock_threshold' => 'integer',
        'notify_stock_low' => 'boolean',
        'notify_invoice_paid' => 'boolean',
        'notify_invoice_overdue' => 'boolean',
        'notify_team_joined' => 'boolean',
    ];

    /**
     * Get the user that owns the settings.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
