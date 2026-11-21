<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'actor_id',
        'kind',
        'from',
        'to',
        'note',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'actor_id' => 'integer',
            'at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
