<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    public const REASON_TYPES = ['quality', 'deadlines', 'teamwork', 'attitude', 'incident', 'other'];

    public $timestamps = false;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'rater_id',
        'score',
        'reason_type',
        'reason',
        'project_id',
        'project_name',
        'project_name_en',
        'period_from',
        'period_to',
        'private',
        'created_at',
        'edited_at',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'rater_id' => 'integer',
            'score' => 'integer',
            'project_id' => 'integer',
            'private' => 'boolean',
            'created_at' => 'datetime',
            'edited_at' => 'datetime',
            'edited_by' => 'integer',
        ];
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
