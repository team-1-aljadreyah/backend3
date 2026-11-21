<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'author_id',
        'body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'author_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
