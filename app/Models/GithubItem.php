<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'external_id',
        'kind',
        'repo',
        'ref',
        'title',
        'state',
        'author',
        'at',
        'url',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
