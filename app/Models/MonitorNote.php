<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitorNote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'kind',
        'title',
        'body',
        'about_type',
        'about_id',
        'about_label',
        'about_label_en',
        'tone',
        'subject',
        'people',
        'author_id',
        'created_at',
        'sent_at',
        'ceo_state',
        'ceo_reply',
        'replied_at',
        'facts',
        'triage',
        'forward_note',
        'monitor_reply',
        'triaged_by',
        'triaged_at',
    ];

    protected function casts(): array
    {
        return [
            'about_id' => 'integer',
            'people' => 'array',
            'author_id' => 'integer',
            'created_at' => 'datetime',
            'sent_at' => 'datetime',
            'replied_at' => 'datetime',
            'facts' => 'array',
            'triaged_by' => 'integer',
            'triaged_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(MonitorNoteShare::class, 'note_id')->orderBy('id');
    }
}
