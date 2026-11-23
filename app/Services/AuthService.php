<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppSetting;
use App\Models\AuthSession;
use App\Models\LoginSecurity;
use App\Models\User;
use App\Support\BaghdadTime;
use App\Support\ClientContext;
use App\Support\PasswordRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private readonly PushService $push;

    private readonly AdvancedSettingsService $advanced;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        ?PushService $push = null,
        ?AdvancedSettingsService $advanced = null,
    ) {
        $this->push = $push ?? app(PushService::class);
        $this->advanced = $advanced ?? app(AdvancedSettingsService::class);
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

    public function changeMyPassword(User $user, string $currentPassword, string $newPassword): void
    {
        $settings = AppSetting::current();
        $nowUtc = now('UTC');

        $sec = DB::transaction(function () use ($user) {
            return LoginSecurity::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? LoginSecurity::create(['user_id' => $user->id, 'failed_logins' => 0]);
        });

        if ($sec->locked_until && $sec->locked_until->gt($nowUtc)) {
            $until = $sec->locked_until->setTimezone(BaghdadTime::timezone())->format('H:i');
            throw ApiException::rateLimited('api.auth.account_locked', ['until' => $until]);
        }

        if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
            $this->audit->log(null, 'login_failed', [
                'type' => 'login',
                'id' => $user->id,
                'label' => $user->email,
            ]);

            DB::transaction(function () use ($user, $settings, $nowUtc) {
                $lockedSec = LoginSecurity::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $lockedSec->failed_logins++;
                if ($lockedSec->failed_logins >= $settings->lock_after) {
                    $lockedSec->locked_until = $nowUtc->copy()->addMinutes($settings->lock_minutes);
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
                $lockedSec->save();
            });

            throw ApiException::validation('current_password', 'api.password.wrong_current');
        }

        if ($sec->failed_logins > 0) {
            $sec->failed_logins = 0;
            $sec->save();
        }

        PasswordRule::checkOrFail($newPassword, $this->advanced->passwordMin());

        if ($newPassword === $currentPassword) {
            throw ApiException::validation('password', 'api.password.same_as_current');
        }

        DB::transaction(function () use ($user, $newPassword, $nowUtc) {
            $user->password = $newPassword;
            $user->save();

            $this->audit->log($user->id, 'user_changed', [
                'type' => 'user',
                'id' => $user->id,
                'label' => $user->name,
            ], [['field' => 'password']]);

            // End other active sessions so stale tokens cannot remain valid after a password change.
            $token = $user->currentAccessToken();
            $currentSid = ($token instanceof PersonalAccessToken && ! empty($token->session_id))
                ? (string) $token->session_id
                : null;

            $others = AuthSession::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->when($currentSid !== null, fn ($q) => $q->where('id', '!=', $currentSid))
                ->get();

            if ($others->isNotEmpty()) {
                $sids = $others->pluck('id')->all();
                $tids = $others->pluck('token_id')->filter()->map(fn ($id) => (int) $id)->all();
                AuthSession::query()->whereIn('id', $sids)->update([
                    'ended_at' => $nowUtc,
                    'ended_by' => $user->id,
                    'end_reason' => 'everywhere',
                ]);
                if ($tids !== []) {
                    PersonalAccessToken::query()->whereIn('id', $tids)->delete();
                }
                $this->push->deleteForSessions($sids);
            }
        });
    }
}
