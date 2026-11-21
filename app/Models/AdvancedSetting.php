<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvancedSetting extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'rules',
        'updated_by',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'rules' => 'array',
            'updated_by' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
