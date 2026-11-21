<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bug extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    public const STATUSES = ['reported', 'assigned', 'fixing', 'testing', 'reopened', 'closed'];

    protected $fillable = [
        'code',
        'project_id',
        'phase_id',
        'task_id',
        'title',
        'description',
        'severity',
        'priority',
        'status',
        'reported_by',
        'assignee_id',
        'due',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'phase_id' => 'integer',
            'task_id' => 'integer',
            'reported_by' => 'integer',
            'assignee_id' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BugEvent::class)->orderBy('id');
    }
}
