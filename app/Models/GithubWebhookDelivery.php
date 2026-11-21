<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubWebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'event',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
