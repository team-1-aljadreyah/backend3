<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    public const STATES = ['planned', 'active', 'paused', 'done'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'description',
        'pm_id',
        'start',
        'end',
        'state',
        'priority',
        'uses_sprints',
    ];

    protected function casts(): array
    {
        return [
            'pm_id' => 'integer',
            'uses_sprints' => 'boolean',
        ];
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class)->orderBy('position');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderBy('position');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class)->orderBy('start');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class)->orderBy('at')->orderBy('id');
    }

    public function githubSetting(): HasOne
    {
        return $this->hasOne(ProjectGithubSetting::class);
    }

    public function memberIds(): array
    {
        return $this->members->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
