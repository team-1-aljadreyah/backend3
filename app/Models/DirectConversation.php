<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectConversation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_low_id',
        'user_high_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'user_low_id' => 'integer',
            'user_high_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function memberIds(): array
    {
        return [$this->user_low_id, $this->user_high_id];
    }
}
