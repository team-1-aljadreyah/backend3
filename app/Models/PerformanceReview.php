<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceReview extends Model
{
    protected $fillable = [
        'user_id',
        'reviewer_id',
        'period_from',
        'period_to',
        'scores',
        'criteria',
        'total',
        'note',
        'perf',
        'state',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reviewer_id' => 'integer',
            'scores' => 'array',
            'criteria' => 'array',
            'total' => 'integer',
            'perf' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function scoreRows(): HasMany
    {
        return $this->hasMany(PerformanceReviewScore::class, 'review_id');
    }
}
