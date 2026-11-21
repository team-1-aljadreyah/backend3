<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'actor_id',
        'kind',
        'from',
        'to',
        'note',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'actor_id' => 'integer',
            'at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
