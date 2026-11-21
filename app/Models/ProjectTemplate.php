<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTemplate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'statuses',
        'phases',
        'uses_sprints',
        'tasks',
        'from_project',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'statuses' => 'array',
            'phases' => 'array',
            'uses_sprints' => 'boolean',
            'tasks' => 'array',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
