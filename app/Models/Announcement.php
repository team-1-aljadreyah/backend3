<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'title',
        'body',
        'important',
        'author_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'important' => 'boolean',
            'author_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }
}
