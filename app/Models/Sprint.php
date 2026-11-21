<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'start',
        'end',
        'closed_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'closed_at' => 'datetime',
            'result' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
