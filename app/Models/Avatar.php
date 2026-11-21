<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Avatar extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'disk_path',
        'mime',
        'size',
        'width',
        'height',
        'cache_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        $ext = $this->mime === 'image/webp' ? 'webp' : 'jpg';

        return URL::route('avatars.show', [
            'user' => $this->user_id,
            'file' => "{$this->cache_key}.{$ext}",
        ]);
    }
}
