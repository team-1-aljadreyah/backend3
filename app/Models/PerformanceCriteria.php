<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceCriteria extends Model
{
    protected $table = 'performance_criteria';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'items',
        'approved_by',
        'approved_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            ['items' => [], 'updated_at' => now('UTC')]
        );
    }
}
