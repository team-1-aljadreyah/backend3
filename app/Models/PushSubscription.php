<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'endpoint_hash',
        'endpoint',
        'p256dh',
        'auth',
        'device',
        'created_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
