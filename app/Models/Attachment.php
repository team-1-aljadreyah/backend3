<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    public const OWNER_TYPES = ['task', 'project', 'bug', 'message'];

    public $timestamps = false;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'name',
        'size',
        'mime',
        'disk_path',
        'uploaded_by',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
            'at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
