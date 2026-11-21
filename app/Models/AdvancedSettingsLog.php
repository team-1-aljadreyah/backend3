<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvancedSettingsLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'at',
        'by_id',
        'by_name',
        'by_name_en',
        'how',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'by_id' => 'integer',
            'changes' => 'array',
        ];
    }
}
