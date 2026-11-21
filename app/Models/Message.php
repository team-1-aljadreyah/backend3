<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'chat_key',
        'author_id',
        'body',
        'reply_to_id',
        'mentions',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'reply_to_id' => 'integer',
            'mentions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
