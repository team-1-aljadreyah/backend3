<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['ceo', 'pm', 'monitor', 'lead', 'employee', 'admin'];

    protected $fillable = [
        'name',
        'name_en',
        'email',
        'phone',
        'role',
        'custom_role',
        'birth_date',
        'preferred_locale',
        'title',
        'team_id',
        'active',
        'password',
    ];

    protected $with = [
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'password' => 'hashed',
            'team_id' => 'integer',
            'birth_date' => 'date:Y-m-d',
        ];
    }

    public function isCeo(): bool
    {
        return $this->role === 'ceo';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function loginSecurity(): HasOne
    {
        return $this->hasOne(LoginSecurity::class, 'user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members');
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function avatar(): HasOne
    {
        return $this->hasOne(Avatar::class)->latestOfMany('id');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar?->url();
    }
}
