<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const ACTIONS = [
        'user_created',
        'user_changed',
        'user_stopped',
        'user_restarted',
        'user_unlocked',
        'team_created',
        'team_changed',
        'settings_changed',
        'login_failed',
        'login_locked',
        'project_created',
        'project_pm_changed',
        'sprint_closed',
        'template_deleted',
        'announcement_deleted',
        'criteria_changed',
        'criteria_approved',
        'review_finalized',
        'picture_removed',
    ];

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'changes',
        'at',
        'ip',
        'user_agent',
        'session_id',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_id' => 'integer',
            'changes' => 'array',
            'at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
