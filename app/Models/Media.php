<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une image hébergée (voir App\Services\MediaService). `owner_type` /
 * `owner_id` : l'élément auquel elle est rattachée (null tant qu'un nouvel
 * article n'est pas enregistré).
 */
class Media extends Model
{
    use HasUuids;

    public const KINDS = ['product', 'variation', 'logo', 'avatar'];

    protected $table = 'media';

    protected $fillable = [
        'account_id',
        'owner_type',
        'owner_id',
        'kind',
        'disk',
        'path',
        'url',
        'width',
        'height',
        'size_bytes',
        'mime',
        'created_by_user_id',
    ];

    protected $hidden = ['disk', 'path', 'account_id', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
