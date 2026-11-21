<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectGithubSetting extends Model
{
    protected $fillable = [
        'project_id',
        'enabled',
        'repos',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'enabled' => 'boolean',
            'repos' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
