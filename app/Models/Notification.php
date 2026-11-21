<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const KINDS = [
        'task_assigned',
        'task_status',
        'task_comment',
        'task_due_changed',
        'task_due_soon',
        'task_overdue',
        'approval_requested',
        'approval_decided',
        'project_added',
        'project_pm',
        'project_plan',
        'announcement',
        'bug_reported',
        'bug_assigned',
        'bug_status',
        'bug_late',
        'chat_mention',
        'dependency_done',
        'review_final',
        'team_joined',
        'account_locked',
        'github_pr_merged',
        'note_sent',
        'note_answered',
    ];

    public const REQUIRED_KINDS = [
        'task_assigned',
        'task_overdue',
        'approval_requested',
        'approval_decided',
        'project_added',
        'announcement',
        'bug_assigned',
        'note_sent',
        'note_answered',
    ];

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'kind',
        'actor_id',
        'project_id',
        'task_id',
        'announcement_id',
        'bug_id',
        'chat_key',
        'data',
        'dedup_key',
        'at',
        'read',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'actor_id' => 'integer',
            'project_id' => 'integer',
            'task_id' => 'integer',
            'announcement_id' => 'integer',
            'bug_id' => 'integer',
            'data' => 'array',
            'at' => 'datetime',
            'read' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
