<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'name',
        'name_en',
        'lead_id',
    ];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }
}
