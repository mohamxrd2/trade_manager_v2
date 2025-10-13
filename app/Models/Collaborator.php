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
        'wallet',
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
        'wallet' => 'decimal:2',
    ];

    /**
     * Get the user that owns the collaborator.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
