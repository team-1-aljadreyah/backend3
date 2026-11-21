<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'announcement_id',
        'user_id',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'announcement_id' => 'integer',
            'user_id' => 'integer',
            'at' => 'datetime',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
