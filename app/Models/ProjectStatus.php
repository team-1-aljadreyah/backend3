<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStatus extends Model
{
    public const TONES = ['new', 'todo', 'progress', 'review', 'testing', 'done', 'blocked'];

    public const DEFAULT_STATUSES = [
        ['key' => 'new', 'name' => 'جديدة', 'name_en' => 'New', 'tone' => 'new'],
        ['key' => 'todo', 'name' => 'قيد الانتظار', 'name_en' => 'To do', 'tone' => 'todo'],
        ['key' => 'in_progress', 'name' => 'قيد التنفيذ', 'name_en' => 'In progress', 'tone' => 'progress'],
        ['key' => 'in_review', 'name' => 'قيد المراجعة', 'name_en' => 'In review', 'tone' => 'review'],
        ['key' => 'testing', 'name' => 'قيد الاختبار', 'name_en' => 'Testing', 'tone' => 'testing'],
        ['key' => 'completed', 'name' => 'مكتملة', 'name_en' => 'Completed', 'tone' => 'done'],
        ['key' => 'blocked', 'name' => 'متوقفة', 'name_en' => 'Blocked', 'tone' => 'blocked'],
    ];

    public const BASE_KEYS = ['new', 'todo', 'in_progress', 'in_review', 'testing', 'completed', 'blocked'];

    protected $fillable = [
        'project_id',
        'key',
        'name',
        'name_en',
        'tone',
        'position',
        'is_base',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'position' => 'integer',
            'is_base' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
