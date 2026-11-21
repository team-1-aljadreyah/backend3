<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'chat_key',
        'user_id',
        'last_message_id',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_message_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
