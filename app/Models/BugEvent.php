<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'bug_id',
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
            'bug_id' => 'integer',
            'actor_id' => 'integer',
            'at' => 'datetime',
        ];
    }

    public function bug(): BelongsTo
    {
        return $this->belongsTo(Bug::class);
    }
}
