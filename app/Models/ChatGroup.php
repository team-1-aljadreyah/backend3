<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatGroupMember::class, 'group_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return int[]
     */
    public function memberIds(): array
    {
        return $this->members->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }
}
