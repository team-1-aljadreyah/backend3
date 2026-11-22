<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppSetting;
use App\Models\AuthSession;
use App\Models\LoginSecurity;
use App\Models\User;
use App\Support\BaghdadTime;
use App\Support\ClientContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private readonly PushService $push;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        ?PushService $push = null,
    ) {
        $this->push = $push ?? app(PushService::class);
    }

    /**
     * @return array{token: string, user: User}
     */
    public function login(string $identifier, string $password): array
    {
        $id = mb_strtolower(trim($identifier));
        if ($id === '') {
            throw ApiException::validation('identifier', 'api.auth.identifier_required');
        }
        if ($password === '') {
            throw ApiException::validation('password', 'api.auth.password_required');
        }

        $user = User::query()
            ->where('email', $id)
            ->orWhere(function ($q) use ($id) {
                $q->whereNotNull('phone')->where('phone', $id);
            })
            ->first();

        $settings = AppSetting::current();
        $nowUtc = now('UTC');

        if ($user) {
            $security = DB::transaction(function () use ($user) {
                $sec = LoginSecurity::query()->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $sec) {
                    $sec = LoginSecurity::create([
                        'user_id' => $user->id,
                        'failed_logins' => 0,
                    ]);
                }

                return $sec;
            });

            if ($security->locked_until && $security->locked_until->gt($nowUtc)) {
                $until = $security->locked_until->setTimezone(BaghdadTime::timezone())->format('H:i');
                throw ApiException::rateLimited('api.auth.account_locked', ['until' => $until]);
            }
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->audit->log(null, 'login_failed', [
                'type' => 'login',
                'id' => $user?->id,
                'label' => mb_substr($id, 0, 120),
            ]);

            if ($user) {
                DB::transaction(function () use ($user, $settings, $nowUtc) {
                    $sec = LoginSecurity::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                    $sec->failed_logins++;
                    if ($sec->failed_logins >= $settings->lock_after) {
                        $sec->locked_until = $nowUtc->copy()->addMinutes($settings->lock_minutes);
                        $this->audit->log(null, 'login_locked', [
                            'type' => 'user',
                            'id' => $user->id,
                            'label' => $user->name,
                        ], [
                            ['field' => 'minutes', 'to' => (string) $settings->lock_minutes],
                        ]);

                        $admins = User::query()
                            ->where('role', 'admin')
                            ->where('active', true)
                            ->pluck('id')
                            ->all();

                        $this->notifications->notify($admins, 'account_locked', [
                            'data' => ['note' => $user->name],
                        ]);
                    }
                    $sec->save();
                });
            }

            throw new ApiException(422, 'VALIDATION', __('api.auth.invalid_credentials'));
        }

        if (! $user->active) {
            throw ApiException::forbidden('api.auth.account_stopped');
        }

        LoginSecurity::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'failed_logins' => 0,
                'locked_until' => null,
                'last_login_at' => $nowUtc,
            ]
        );

        $newToken = $user->createToken('api');
        $sessionId = 's_'.bin2hex(random_bytes(8));
        $newToken->accessToken->forceFill([
            'session_id' => $sessionId,
        ])->save();

        AuthSession::create([
            'id' => $sessionId,
            'user_id' => $user->id,
            'token_id' => $newToken->accessToken->id,
            'ip' => ClientContext::clientIp(),
            'user_agent' => ClientContext::userAgent(),
            'started_at' => $nowUtc,
            'last_seen_at' => $nowUtc,
        ]);

        return [
            'token' => $newToken->plainTextToken,
            'user' => $user->loadMissing('avatar'),
        ];
    }

    public function logout(?User $user): void
    {
        if (! $user) {
            return;
        }

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $sessionId = $token->session_id;
            if (! empty($sessionId)) {
                AuthSession::query()
                    ->where('id', $sessionId)
                    ->whereNull('ended_at')
                    ->update([
                        'ended_at' => now('UTC'),
                        'ended_by' => $user->id,
                        'end_reason' => 'signed_out',
                    ]);
                $this->push->deleteForSessions([$sessionId]);
            }
            $token->delete();
        }
    }
}
