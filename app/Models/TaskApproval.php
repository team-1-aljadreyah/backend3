<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskApproval extends Model
{
    protected $fillable = [
        'task_id',
        'state',
        'from_status',
        'to_status',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'note',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
