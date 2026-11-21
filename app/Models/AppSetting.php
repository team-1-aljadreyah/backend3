<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id',
        'due_soon_days',
        'lock_after',
        'lock_minutes',
    ];

    protected function casts(): array
    {
        return [
            'due_soon_days' => 'integer',
            'lock_after' => 'integer',
            'lock_minutes' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            ['due_soon_days' => 1, 'lock_after' => 5, 'lock_minutes' => 15]
        );
    }
}
