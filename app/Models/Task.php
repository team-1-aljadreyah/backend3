<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    public const PRIORITIES = ['high', 'medium', 'low'];

    protected $fillable = [
        'code',
        'project_id',
        'phase_id',
        'sprint_id',
        'title',
        'description',
        'assignee_id',
        'start',
        'due',
        'priority',
        'status',
        'blocked_reason',
        'subtasks_done',
        'subtasks_total',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'phase_id' => 'integer',
            'sprint_id' => 'integer',
            'assignee_id' => 'integer',
            'subtasks_done' => 'integer',
            'subtasks_total' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    public function currentApproval(): HasOne
    {
        return $this->hasOne(TaskApproval::class)->where('is_current', true)->latestOfMany('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TaskApproval::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderBy('id');
    }
}
