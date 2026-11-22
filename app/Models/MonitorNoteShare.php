<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorNoteShare extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'note_id',
        'user_id',
        'label',
        'label_en',
        'shared_at',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'note_id' => 'integer',
            'user_id' => 'integer',
            'shared_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(MonitorNote::class, 'note_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
