<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatGroupMember extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'user_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'user_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChatGroup::class, 'group_id');
    }
}
