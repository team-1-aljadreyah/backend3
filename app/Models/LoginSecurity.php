<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginSecurity extends Model
{
    protected $table = 'login_security';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'failed_logins',
        'locked_until',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
