<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthSession extends Model
{
    public const IDLE_DAYS = 30;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'token_id',
        'ip',
        'user_agent',
        'started_at',
        'last_seen_at',
        'ended_at',
        'ended_by',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'token_id' => 'integer',
            'ended_by' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(?\Carbon\CarbonInterface $now = null): bool
    {
        $now ??= now('UTC');
        if ($this->ended_at !== null) {
            return false;
        }
        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->gt($now->copy()->subDays(self::IDLE_DAYS));
    }
}
