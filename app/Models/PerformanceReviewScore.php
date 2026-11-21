<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReviewScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'review_id',
        'criterion_id',
        'criterion_name',
        'criterion_name_en',
        'kind',
        'metric',
        'weight',
        'stars',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'review_id' => 'integer',
            'weight' => 'integer',
            'stars' => 'integer',
            'score' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }
}
