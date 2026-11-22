<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\AuditLogResource;
use App\Models\AppSetting;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Avatar;
use App\Models\LoginSecurity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use App\Support\Birthday;
use App\Support\SearchNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AdminService
{
    public const LIMITS = ['name' => 120, 'title' => 80, 'teamName' => 80];

    public const IMPORT_LIMIT = 500;

    public const FAILED_DAYS = 7;

    public const RELATED_LIMIT = 20;

    public const IMPORT_ROLES = ['pm', 'monitor', 'lead', 'employee'];

    public const ROLE_WORDS = [
        'ceo' => ['ar' => 'المدير التنفيذي', 'en' => 'CEO'],
        'pm' => ['ar' => 'مدير القسم', 'en' => 'Department manager'],
        'monitor' => ['ar' => 'مسؤول المتابعة والتقييم', 'en' => 'Monitoring & evaluation'],
        'lead' => ['ar' => 'قائد الفريق', 'en' => 'Team leader'],
        'employee' => ['ar' => 'موظف', 'en' => 'Employee'],
        'admin' => ['ar' => 'مسؤول النظام', 'en' => 'System admin'],
    ];

    public const SETTING_RANGES = [
        'due_soon_days' => [1, 7],
        'lock_after' => [3, 10],
        'lock_minutes' => [5, 120],
    ];

    public const ACTION_KIND = [
        'user_created' => 'account',
        'user_changed' => 'account',
        'user_stopped' => 'account',
        'user_restarted' => 'account',
        'user_unlocked' => 'account',
        'team_created' => 'team',
        'team_changed' => 'team',
        'settings_changed' => 'settings',
        'login_failed' => 'signin',
        'login_locked' => 'signin',
        'project_created' => 'project',
        'project_pm_changed' => 'project',
        'sprint_closed' => 'project',
        'template_deleted' => 'project',
        'announcement_deleted' => 'announcement',
        'criteria_changed' => 'review',
        'criteria_approved' => 'review',
        'review_finalized' => 'review',
        'picture_removed' => 'account',
        'session_ended' => 'signin',
        'sessions_ended' => 'signin',
        'users_imported' => 'account',
        'rating_changed' => 'review',
        'rating_deleted' => 'review',
    ];

    public const ACTION_WORDS = [
        'user_created' => ['ar' => 'أضاف مستخدماً', 'en' => 'added a user'],
        'user_changed' => ['ar' => 'عدّل مستخدماً', 'en' => 'changed a user'],
        'user_stopped' => ['ar' => 'أوقف حساباً', 'en' => 'stopped an account'],
        'user_restarted' => ['ar' => 'أعاد تشغيل حساب', 'en' => 'restarted an account'],
        'user_unlocked' => ['ar' => 'فتح حساباً مقفلاً', 'en' => 'unlocked an account'],
        'team_created' => ['ar' => 'أنشأ فريقاً', 'en' => 'created a team'],
        'team_changed' => ['ar' => 'عدّل فريقاً', 'en' => 'changed a team'],
        'settings_changed' => ['ar' => 'غيّر الإعدادات', 'en' => 'changed the settings'],
        'login_failed' => ['ar' => 'محاولة دخول خاطئة', 'en' => 'failed sign-in'],
        'login_locked' => ['ar' => 'قُفل حساب', 'en' => 'account locked'],
        'project_created' => ['ar' => 'أنشأ مشروعاً', 'en' => 'created a project'],
        'project_pm_changed' => ['ar' => 'غيّر مدير قسم', 'en' => 'changed a project\'s department manager'],
        'sprint_closed' => ['ar' => 'أنهى دورة', 'en' => 'ended a sprint'],
        'template_deleted' => ['ar' => 'حذف قالباً', 'en' => 'removed a template'],
        'announcement_deleted' => ['ar' => 'حذف إعلاناً', 'en' => 'removed an announcement'],
        'criteria_changed' => ['ar' => 'غيّر معايير التقييم', 'en' => 'changed the review criteria'],
        'criteria_approved' => ['ar' => 'اعتمد معايير التقييم', 'en' => 'approved the review criteria'],
        'review_finalized' => ['ar' => 'اعتمد تقييم أداء', 'en' => 'made a review final'],
        'picture_removed' => ['ar' => 'أزال صورة شخصية', 'en' => 'removed a profile picture'],
        'session_ended' => ['ar' => 'أنهى جلسة دخول', 'en' => 'ended a sign-in session'],
        'sessions_ended' => ['ar' => 'أخرج حساباً من كل الأجهزة', 'en' => 'signed an account out everywhere'],
        'users_imported' => ['ar' => 'استورد حسابات', 'en' => 'imported accounts'],
        'rating_changed' => ['ar' => 'عدّل تقييماً سريعاً كتبه غيره', 'en' => 'changed someone else\'s quick rating'],
        'rating_deleted' => ['ar' => 'حذف تقييماً سريعاً', 'en' => 'removed a quick rating'],
    ];

    private readonly PushService $push;

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
        ?PushService $push = null,
    ) {
        $this->push = $push ?? app(PushService::class);
    }

    /**
     * @return Collection<int, User>
     */
    public function users(User $actor): Collection
    {
        $this->requireAdmin($actor);

        return User::query()->with('loginSecurity')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createUser(User $actor, array $input): User
    {
        $this->requireAdmin($actor);
        $baseRole = $this->resolveCustomRoleBase($input);
        if ($baseRole !== null) {
            $input['role'] = $baseRole;
        }
        $this->checkUser($input, null, true);

        return DB::transaction(function () use ($actor, $input, $baseRole) {
            $password = ! empty($input['password']) ? (string) $input['password'] : Str::random(32);
            $customRole = ($baseRole !== null && ! empty($input['custom_role'])) ? (string) $input['custom_role'] : null;

            $user = User::create([
                'name' => trim((string) $input['name']),
                'name_en' => isset($input['name_en']) && trim((string) $input['name_en']) !== '' ? trim((string) $input['name_en']) : null,
                'email' => mb_strtolower(trim((string) $input['email'])),
                'phone' => isset($input['phone']) && trim((string) $input['phone']) !== '' ? trim((string) $input['phone']) : null,
                'role' => $input['role'],
                'custom_role' => $customRole,
                'birth_date' => ! empty($input['birth_date']) ? (string) $input['birth_date'] : null,
                'title' => trim((string) ($input['title'] ?? '')),
                'team_id' => $input['team_id'] ?? null,
                'active' => true,
                'password' => $password,
            ]);

            LoginSecurity::create([
                'user_id' => $user->id,
                'failed_logins' => 0,
            ]);

            $changes = [['field' => 'role', 'to' => $user->role]];
            if ($user->custom_role !== null) {
                $changes[] = ['field' => 'custom_role', 'to' => $this->roleLabel($user->custom_role)];
            }
            if ($user->team_id !== null) {
                $teamName = Team::query()->find($user->team_id)?->name;
                $changes[] = ['field' => 'team', 'to' => $teamName];
                $this->notifications->notify([$user->id], 'team_joined', [
                    'actor_id' => $actor->id,
                    'data' => ['note' => $teamName],
                ]);
            }

            $this->audit->log($actor->id, 'user_created', [
                'type' => 'user',
                'id' => $user->id,
                'label' => $user->name,
            ], $changes);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function updateUser(User $actor, int $id, array $patch): User
    {
        $this->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $patch) {
            $user = User::query()->lockForUpdate()->find($id);
            if (! $user) {
                throw ApiException::notFound('api.admin.user_not_found');
            }

            $baseRole = $this->resolveCustomRoleBase($patch);
            if ($baseRole !== null) {
                $patch['role'] = $baseRole;
            }

            $this->checkUser($patch, $id, false);

            $isSelf = (int) $user->id === (int) $actor->id;
            $nextCustomParam = array_key_exists('custom_role', $patch) ? ($patch['custom_role'] ?: null) : $user->custom_role;
            $changingOwnRole = (array_key_exists('role', $patch) && $patch['role'] !== $user->role)
                || (array_key_exists('custom_role', $patch) && $nextCustomParam !== $user->custom_role);
            $stoppingSelf = array_key_exists('active', $patch) && $patch['active'] === false;
            if ($isSelf && ($changingOwnRole || $stoppingSelf)) {
                throw ApiException::badState('api.admin.self_guard');
            }

            $losesAdmin = $user->role === 'admin' && ((array_key_exists('role', $patch) && $patch['role'] !== 'admin') || $stoppingSelf);
            if ($losesAdmin) {
                $activeAdmins = User::query()->where('role', 'admin')->where('active', true)->count();
                if ($activeAdmins <= 1) {
                    throw ApiException::badState('api.admin.last_admin');
                }
            }

            $losesPm = $user->role === 'pm' && ((array_key_exists('role', $patch) && $patch['role'] !== 'pm') || $stoppingSelf);
            if ($losesPm) {
                $openProjects = Project::query()
                    ->where('pm_id', $user->id)
                    ->where('state', '!=', 'done')
                    ->pluck('code')
                    ->all();
                if ($openProjects !== []) {
                    $sep = app()->getLocale() === 'ar' ? '، ' : ', ';
                    throw ApiException::badState('api.admin.pm_open_projects', ['codes' => implode($sep, $openProjects)]);
                }
            }

            $changes = [];
            $apply = function (string $field, mixed $value, ?string $label = null) use ($user, &$changes) {
                if ($value === $user->{$field}) {
                    return;
                }
                $changes[] = [
                    'field' => $label ?? $field,
                    'from' => $user->{$field} !== null ? (string) $user->{$field} : null,
                    'to' => $value !== null ? (string) $value : null,
                ];
                $user->{$field} = $value;
            };

            if (array_key_exists('name', $patch)) {
                $apply('name', trim((string) $patch['name']));
            }
            if (array_key_exists('name_en', $patch)) {
                $apply('name_en', trim((string) ($patch['name_en'] ?? '')) ?: null);
            }
            if (array_key_exists('email', $patch)) {
                $apply('email', mb_strtolower(trim((string) $patch['email'])));
            }
            if (array_key_exists('phone', $patch)) {
                $apply('phone', trim((string) ($patch['phone'] ?? '')) ?: null);
            }
            if (array_key_exists('title', $patch)) {
                $apply('title', trim((string) ($patch['title'] ?? '')));
            }
            if (array_key_exists('role', $patch)) {
                $apply('role', $patch['role']);
            }
            $candidateCustom = array_key_exists('custom_role', $patch)
                ? (($patch['custom_role'] !== null && $patch['custom_role'] !== '') ? (string) $patch['custom_role'] : null)
                : $user->custom_role;
            $customRoleDef = $this->advanced->findRoleByKey($candidateCustom);
            $finalCustom = ($customRoleDef !== null && ($customRoleDef['base'] ?? '') === $user->role) ? $candidateCustom : null;
            if ($finalCustom !== $user->custom_role) {
                $changes[] = [
                    'field' => 'custom_role',
                    'from' => $this->roleLabel($user->custom_role),
                    'to' => $this->roleLabel($finalCustom),
                ];
                $user->custom_role = $finalCustom;
            }
            if (array_key_exists('team_id', $patch)) {
                $nextTeam = $patch['team_id'] !== null ? (int) $patch['team_id'] : null;
                $prevTeam = $user->team_id !== null ? (int) $user->team_id : null;
                if ($nextTeam !== $prevTeam) {
                    $oldName = $prevTeam !== null ? Team::query()->find($prevTeam)?->name : null;
                    $newName = $nextTeam !== null ? Team::query()->find($nextTeam)?->name : null;
                    $changes[] = ['field' => 'team', 'from' => $oldName, 'to' => $newName];
                    $user->team_id = $nextTeam;
                    if ($nextTeam !== null) {
                        $this->notifications->notify([$user->id], 'team_joined', [
                            'actor_id' => $actor->id,
                            'data' => ['note' => $newName],
                        ]);
                    }
                }
            }
            if (array_key_exists('birth_date', $patch)) {
                $nextBirth = ($patch['birth_date'] !== null && $patch['birth_date'] !== '') ? (string) $patch['birth_date'] : null;
                $prevBirth = $user->birth_date instanceof CarbonInterface
                    ? $user->birth_date->format('Y-m-d')
                    : (is_string($user->birth_date) && $user->birth_date !== '' ? substr($user->birth_date, 0, 10) : null);

                if ($nextBirth !== $prevBirth) {
                    // Privacy: audit records that birth_date changed, never old/new dates.
                    $changes[] = ['field' => 'birth_date'];
                    $user->birth_date = $nextBirth;
                }
            }

            if ($changes !== []) {
                $this->audit->log($actor->id, 'user_changed', [
                    'type' => 'user',
                    'id' => $user->id,
                    'label' => $user->name,
                ], $changes);
            }

            if (array_key_exists('active', $patch) && (bool) $patch['active'] !== (bool) $user->active) {
                $user->active = (bool) $patch['active'];
                $this->audit->log($actor->id, $user->active ? 'user_restarted' : 'user_stopped', [
                    'type' => 'user',
                    'id' => $user->id,
                    'label' => $user->name,
                ]);
                if (! $user->active) {
                    $activeSessions = AuthSession::query()
                        ->where('user_id', $user->id)
                        ->whereNull('ended_at')
                        ->get();
                    $this->endSessions($activeSessions, (int) $actor->id, 'stopped');
                    $user->tokens()->delete();
                }
            }

            if (! empty($patch['unlock'])) {
                $sec = LoginSecurity::query()->where('user_id', $user->id)->lockForUpdate()->first();
                if ($sec && ($sec->locked_until !== null || $sec->failed_logins > 0)) {
                    $sec->failed_logins = 0;
                    $sec->locked_until = null;
                    $sec->save();
                    $this->audit->log($actor->id, 'user_unlocked', [
                        'type' => 'user',
                        'id' => $user->id,
                        'label' => $user->name,
                    ]);
                }
            }

            $user->save();

            return $user->fresh();
        });
    }

    /**
     * @return Collection<int, Team>
     */
    public function teams(): Collection
    {
        return Team::query()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createTeam(User $actor, array $input): Team
    {
        $this->requireAdmin($actor);
        $name = $this->checkTeam($input, null);

        return DB::transaction(function () use ($actor, $input, $name) {
            $leadId = ! empty($input['lead_id']) ? (int) $input['lead_id'] : null;
            $team = Team::create([
                'name' => $name,
                'name_en' => isset($input['name_en']) && trim((string) $input['name_en']) !== '' ? trim((string) $input['name_en']) : null,
                'lead_id' => $leadId,
            ]);

            $this->joinLead($team, $leadId, $actor->id);

            $changes = null;
            if ($leadId !== null) {
                $leadName = User::query()->find($leadId)?->name;
                $changes = [['field' => 'lead', 'to' => $leadName]];
            }

            $this->audit->log($actor->id, 'team_created', [
                'type' => 'team',
                'id' => $team->id,
                'label' => $team->name,
            ], $changes);

            return $team;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function updateTeam(User $actor, int $id, array $patch): Team
    {
        $this->requireAdmin($actor);
        $team = Team::query()->find($id);
        if (! $team) {
            throw ApiException::notFound('api.admin.team_not_found_404');
        }

        $name = $this->checkTeam($patch, $id);

        return DB::transaction(function () use ($actor, $team, $patch, $name) {
            $newLeadId = array_key_exists('lead_id', $patch)
                ? ($patch['lead_id'] !== null ? (int) $patch['lead_id'] : null)
                : $team->lead_id;

            $changes = [];
            if ($name !== $team->name) {
                $changes[] = ['field' => 'name', 'from' => $team->name, 'to' => $name];
            }
            if ($newLeadId !== ($team->lead_id !== null ? (int) $team->lead_id : null)) {
                $oldLead = $team->lead_id ? User::query()->find($team->lead_id)?->name : null;
                $newLead = $newLeadId ? User::query()->find($newLeadId)?->name : null;
                $changes[] = ['field' => 'lead', 'from' => $oldLead, 'to' => $newLead];
            }

            $team->name = $name;
            $team->name_en = isset($patch['name_en']) && trim((string) $patch['name_en']) !== '' ? trim((string) $patch['name_en']) : null;
            $team->lead_id = $newLeadId;
            $team->save();

            $this->joinLead($team, $newLeadId, $actor->id);

            if ($changes !== []) {
                $this->audit->log($actor->id, 'team_changed', [
                    'type' => 'team',
                    'id' => $team->id,
                    'label' => $team->name,
                ], $changes);
            }

            return $team->fresh();
        });
    }

    /**
     * @return array<string, int>
     */
    public function settings(User $actor): array
    {
        $this->requireAdmin($actor);
        $s = AppSetting::current();

        return [
            'due_soon_days' => (int) $s->due_soon_days,
            'lock_after' => (int) $s->lock_after,
            'lock_minutes' => (int) $s->lock_minutes,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    public function saveSettings(User $actor, array $input): array
    {
        $this->requireAdmin($actor);

        foreach (self::SETTING_RANGES as $key => [$min, $max]) {
            $val = $input[$key] ?? null;
            if (! is_int($val) || $val < $min || $val > $max) {
                throw ApiException::validation($key, 'api.admin.setting_out_of_range');
            }
        }

        return DB::transaction(function () use ($actor, $input) {
            $current = AppSetting::current();
            $changes = [];
            foreach (array_keys(self::SETTING_RANGES) as $key) {
                if ((int) $input[$key] !== (int) $current->{$key}) {
                    $changes[] = [
                        'field' => $key,
                        'from' => (string) $current->{$key},
                        'to' => (string) $input[$key],
                    ];
                }
            }

            $current->due_soon_days = (int) $input['due_soon_days'];
            $current->lock_after = (int) $input['lock_after'];
            $current->lock_minutes = (int) $input['lock_minutes'];
            $current->save();

            if ($changes !== []) {
                $this->audit->log($actor->id, 'settings_changed', [
                    'type' => 'settings',
                    'label' => 'الإعدادات',
                ], $changes);
            }

            return [
                'due_soon_days' => (int) $current->due_soon_days,
                'lock_after' => (int) $current->lock_after,
                'lock_minutes' => (int) $current->lock_minutes,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function health(User $actor): array
    {
        $this->requireAdmin($actor);
        $now = now('UTC');

        $voiceQuery = Attachment::query()->where(function ($q) {
            $q->whereNotNull('voice')->orWhere('mime', 'like', 'audio/%');
        });
        $nonVoiceQuery = Attachment::query()->whereNull('voice')->where('mime', 'not like', 'audio/%');

        $picturesCount = Avatar::query()->count();
        $picturesBytes = (int) Avatar::query()->sum('size');
        $auditCount = AuditLog::query()->count();
        $auditBytes = $this->estimateAuditBytes($auditCount);

        return [
            'users_active' => User::query()->where('active', true)->count(),
            'users_stopped' => User::query()->where('active', false)->count(),
            'users_locked' => LoginSecurity::query()->whereNotNull('locked_until')->where('locked_until', '>', $now)->count(),
            'projects' => Project::query()->count(),
            'tasks' => Task::query()->count(),
            'files' => Attachment::query()->count(),
            'files_bytes' => (int) Attachment::query()->sum('size'),
            'audit_entries' => $auditCount,
            'storage' => [
                'attachments_bytes' => (int) (clone $nonVoiceQuery)->sum('size'),
                'attachments' => (clone $nonVoiceQuery)->count(),
                'pictures_bytes' => $picturesBytes,
                'pictures' => $picturesCount,
                'voice_bytes' => (int) (clone $voiceQuery)->sum('size'),
                'voice' => (clone $voiceQuery)->count(),
                'audit_bytes' => $auditBytes,
            ],
            'storage_quota_bytes' => (int) config('services.health.storage_quota_bytes', 5 * 1024 * 1024 * 1024),
            'backup' => $this->readBackupState(),
            'server_version' => (string) config('services.health.server_version', '2.2.0'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sessions(User $actor): array
    {
        $this->requireSessionsAdmin($actor);
        $now = now('UTC');
        $idleEdge = $now->copy()->subDays(AuthSession::IDLE_DAYS);
        $currentId = $this->currentSessionId($actor);

        return AuthSession::query()
            ->whereNull('ended_at')
            ->where('last_seen_at', '>', $idleEdge)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(function (AuthSession $s) use ($currentId) {
                return ApiResponse::clean([
                    'id' => $s->id,
                    'user_id' => (int) $s->user_id,
                    'ip' => $s->ip,
                    'user_agent' => $s->user_agent,
                    'started_at' => BaghdadTime::iso($s->started_at),
                    'last_seen_at' => BaghdadTime::iso($s->last_seen_at),
                    'current' => $currentId !== null && $s->id === $currentId,
                ]);
            })
            ->values()
            ->all();
    }

    public function endSession(User $actor, string $sessionId): void
    {
        $this->requireSessionsAdmin($actor);

        $session = AuthSession::query()->find($sessionId);
        if (! $session) {
            throw ApiException::notFound('api.admin.session_not_found');
        }
        if (! $session->isActive(now('UTC'))) {
            throw ApiException::notFound('api.admin.session_already_ended');
        }

        $currentId = $this->currentSessionId($actor);
        if ($currentId !== null && $session->id === $currentId) {
            throw ApiException::badState('api.admin.session_current_self');
        }

        DB::transaction(function () use ($actor, $session) {
            $this->endSessions(collect([$session]), (int) $actor->id, 'admin');
        });
    }

    /**
     * @return array{ended: int}
     */
    public function endUserSessions(User $actor, int $userId): array
    {
        $this->requireSessionsAdmin($actor);
        if (! User::query()->where('id', $userId)->exists()) {
            throw ApiException::notFound('api.admin.user_not_found');
        }

        $now = now('UTC');
        $idleEdge = $now->copy()->subDays(AuthSession::IDLE_DAYS);
        $currentId = $this->currentSessionId($actor);

        $list = AuthSession::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at')
            ->where('last_seen_at', '>', $idleEdge)
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->get();

        DB::transaction(function () use ($actor, $list) {
            $this->endSessions($list, (int) $actor->id, 'everywhere');
        });

        return ['ended' => $list->count()];
    }

    public function unlockUser(User $actor, int $userId): User
    {
        $this->requireAdmin($actor);
        $user = User::query()->with('loginSecurity')->find($userId);
        if (! $user) {
            throw ApiException::notFound('api.admin.user_not_found');
        }

        $sec = $user->loginSecurity;
        if (! $sec || ! $sec->locked_until || ! $sec->locked_until->gt(now('UTC'))) {
            throw ApiException::badState('api.admin.not_locked');
        }

        DB::transaction(function () use ($actor, $user, $sec) {
            $sec->failed_logins = 0;
            $sec->locked_until = null;
            $sec->save();

            $this->audit->log($actor->id, 'user_unlocked', [
                'type' => 'user',
                'id' => $user->id,
                'label' => $user->name,
            ]);
        });

        return $user->fresh('loginSecurity');
    }

    /**
     * @return array<string, mixed>
     */
    public function securitySummary(User $actor): array
    {
        $this->requireSessionsAdmin($actor);

        $now = now('UTC');
        $from = $now->copy()->subDays(self::FAILED_DAYS);
        $settings = AppSetting::current();

        $failed = AuditLog::query()
            ->where('action', 'login_failed')
            ->where('at', '>=', $from)
            ->where('at', '<=', $now)
            ->get();

        $accounts = [];
        $ips = [];

        foreach ($failed as $entry) {
            $key = $entry->target_id !== null
                ? '#'.$entry->target_id
                : mb_strtolower((string) $entry->target_label);

            $atIso = BaghdadTime::iso($entry->at) ?? '';
            $atTs = $entry->at?->getTimestamp() ?? 0;

            if (! isset($accounts[$key])) {
                $row = [
                    'label' => (string) $entry->target_label,
                    'count' => 0,
                    'last_at' => $atIso,
                    'last_ts' => $atTs,
                    'ips' => [],
                ];
                if ($entry->target_id !== null) {
                    $row['user_id'] = (int) $entry->target_id;
                }
                $accounts[$key] = $row;
            }
            $accounts[$key]['count']++;
            if ($atTs > $accounts[$key]['last_ts']) {
                $accounts[$key]['last_ts'] = $atTs;
                $accounts[$key]['last_at'] = $atIso;
            }
            if ($entry->ip !== null && $entry->ip !== '' && ! in_array($entry->ip, $accounts[$key]['ips'], true)) {
                $accounts[$key]['ips'][] = $entry->ip;
            }

            $ipVal = ($entry->ip !== null && $entry->ip !== '') ? $entry->ip : '-';
            if (! isset($ips[$ipVal])) {
                $ips[$ipVal] = [
                    'ip' => $ipVal,
                    'count' => 0,
                    'last_at' => $atIso,
                    'last_ts' => $atTs,
                    'who' => [],
                ];
            }
            $ips[$ipVal]['count']++;
            if ($atTs > $ips[$ipVal]['last_ts']) {
                $ips[$ipVal]['last_ts'] = $atTs;
                $ips[$ipVal]['last_at'] = $atIso;
            }
            $ips[$ipVal]['who'][$key] = true;
        }

        $sortMost = function (array $a, array $b): int {
            if ($b['count'] !== $a['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcmp($b['last_at'], $a['last_at']);
        };

        $byAccount = array_values(array_map(function (array $row) {
            unset($row['last_ts']);

            return $row;
        }, $accounts));
        usort($byAccount, $sortMost);

        $byIp = array_values(array_map(function (array $row) {
            $accountsCount = count($row['who']);
            unset($row['last_ts'], $row['who']);
            $row['accounts'] = $accountsCount;

            return $row;
        }, $ips));
        usort($byIp, $sortMost);

        $locked = LoginSecurity::query()
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', $now)
            ->orderBy('locked_until')
            ->get()
            ->map(fn (LoginSecurity $s) => [
                'user_id' => (int) $s->user_id,
                'locked_until' => BaghdadTime::iso($s->locked_until),
                'failed' => (int) $s->failed_logins,
            ])
            ->values()
            ->all();

        $neverSignedIn = User::query()
            ->where('active', true)
            ->with('loginSecurity')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => $u->loginSecurity?->last_login_at === null)
            ->map(function (User $u) {
                $row = ['user_id' => (int) $u->id];
                $created = BaghdadTime::iso($u->created_at);
                if ($created !== null) {
                    $row['created_at'] = $created;
                }

                return $row;
            })
            ->values()
            ->all();

        return [
            'days' => self::FAILED_DAYS,
            'failed_total' => $failed->count(),
            'by_account' => $byAccount,
            'by_ip' => $byIp,
            'locked' => $locked,
            'never_signed_in' => $neverSignedIn,
            'lock_after' => (int) $settings->lock_after,
            'lock_minutes' => (int) $settings->lock_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function importUsers(User $actor, mixed $rawRows, bool $dryRun): array
    {
        $this->requireAdmin($actor);

        if (! is_array($rawRows) || $rawRows === []) {
            throw ApiException::validation('rows', 'api.admin.import_empty');
        }
        if (count($rawRows) > self::IMPORT_LIMIT) {
            throw ApiException::validation('rows', 'api.admin.import_limit', ['max' => self::IMPORT_LIMIT]);
        }

        $existingEmails = User::query()->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();
        $taken = array_fill_keys($existingEmails, true);
        $teams = Team::query()->orderBy('id')->get();

        $firstLine = [];
        $checks = [];
        $validRows = [];

        foreach (array_values($rawRows) as $idx => $raw) {
            $line = $idx + 1;
            $row = is_array($raw) ? $raw : [];
            $problems = [];

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $problems[] = ['field' => 'name', 'problem' => 'name_missing'];
            } elseif (mb_strlen($name) > self::LIMITS['name']) {
                $problems[] = ['field' => 'name', 'problem' => 'name_too_long'];
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($email === '') {
                $problems[] = ['field' => 'email', 'problem' => 'email_missing'];
            } elseif (! $validEmail) {
                $problems[] = ['field' => 'email', 'problem' => 'email_bad'];
            } elseif (isset($firstLine[$email])) {
                $problems[] = ['field' => 'email', 'problem' => 'email_twice', 'other_line' => $firstLine[$email]];
            } elseif (isset($taken[$email])) {
                $problems[] = ['field' => 'email', 'problem' => 'email_taken'];
            }
            if ($validEmail && ! isset($firstLine[$email])) {
                $firstLine[$email] = $line;
            }

            $role = $this->roleFromText((string) ($row['role'] ?? ''));
            if ($role === null) {
                $problems[] = ['field' => 'role', 'problem' => 'role_unknown'];
            } elseif (! in_array($role, self::IMPORT_ROLES, true)) {
                $problems[] = ['field' => 'role', 'problem' => 'role_not_allowed'];
            }

            $title = trim((string) ($row['title'] ?? ''));
            if (mb_strlen($title) > self::LIMITS['title']) {
                $problems[] = ['field' => 'title', 'problem' => 'title_too_long'];
            }

            $teamText = trim((string) ($row['team'] ?? ''));
            $matchedTeam = null;
            if ($teamText !== '') {
                $matchedTeam = $this->teamFromText($teamText, $teams);
                if ($matchedTeam === null) {
                    $problems[] = ['field' => 'team', 'problem' => 'team_unknown'];
                }
            }

            $checks[$idx] = ['line' => $line, 'problems' => $problems];
            if ($problems === []) {
                $validRows[$idx] = [
                    'name' => $name,
                    'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
                    'email' => $email,
                    'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
                    'role' => $role,
                    'title' => $title,
                    'team' => $matchedTeam,
                ];
            }
        }

        if ($dryRun) {
            $skipped = count(array_filter($checks, fn ($c) => $c['problems'] !== []));

            return [
                'dry_run' => true,
                'rows' => $checks,
                'created' => 0,
                'skipped' => $skipped,
            ];
        }

        return DB::transaction(function () use ($actor, $checks, $validRows) {
            $created = 0;

            foreach ($validRows as $idx => $data) {
                /** @var Team|null $team */
                $team = $data['team'];
                $user = User::create([
                    'name' => $data['name'],
                    'name_en' => $data['name_en'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'role' => $data['role'],
                    'title' => $data['title'],
                    'team_id' => $team?->id,
                    'active' => true,
                    'password' => Str::random(32),
                ]);

                LoginSecurity::create([
                    'user_id' => $user->id,
                    'failed_logins' => 0,
                ]);

                $checks[$idx]['user_id'] = (int) $user->id;
                $created++;

                $changes = [['field' => 'role', 'to' => $user->role]];
                if ($team !== null) {
                    $changes[] = ['field' => 'team', 'to' => $team->name];
                }
                $changes[] = ['field' => 'source', 'to' => 'import'];

                $this->audit->log($actor->id, 'user_created', [
                    'type' => 'user',
                    'id' => $user->id,
                    'label' => $user->name,
                ], $changes);

                if ($team !== null) {
                    $this->notifications->notify([$user->id], 'team_joined', [
                        'actor_id' => $actor->id,
                        'data' => ['note' => $team->name],
                    ]);
                }
            }

            $skipped = count($checks) - $created;
            $this->audit->log($actor->id, 'users_imported', [
                'type' => 'import',
                'label' => "{$created} / ".count($checks),
            ], [
                ['field' => 'created', 'to' => (string) $created],
                ['field' => 'skipped', 'to' => (string) $skipped],
            ]);

            return [
                'dry_run' => false,
                'rows' => $checks,
                'created' => $created,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * @param  array{action?: string|null, actor_id?: int|string|null, kind?: string|null, ip?: string|null, q?: string|null, from?: string|null, to?: string|null}  $filter
     * @return Collection<int, AuditLog>
     */
    public function audit(User $actor, array $filter): Collection
    {
        if (! $this->access->canSeeAudit($actor)) {
            throw ApiException::forbidden('api.admin.audit_forbidden');
        }

        $query = AuditLog::query()->orderByDesc('at')->orderByDesc('id');

        if (! empty($filter['action'])) {
            $query->where('action', $filter['action']);
        }
        if (! empty($filter['actor_id'])) {
            $query->where('actor_id', (int) $filter['actor_id']);
        }
        if (! empty($filter['kind'])) {
            $kind = (string) $filter['kind'];
            $matchingActions = array_keys(array_filter(self::ACTION_KIND, fn (string $k) => $k === $kind));
            if ($matchingActions === []) {
                return collect();
            }
            $query->whereIn('action', $matchingActions);
        }

        $entries = $query->get();

        $from = ! empty($filter['from']) ? (string) $filter['from'] : null;
        $to = ! empty($filter['to']) ? (string) $filter['to'] : null;
        $ipFilter = ! empty($filter['ip']) ? (string) $filter['ip'] : null;
        $q = trim((string) ($filter['q'] ?? ''));

        $actorsById = [];
        if ($q !== '') {
            $actorsById = User::query()
                ->get(['id', 'name', 'name_en', 'email'])
                ->keyBy('id')
                ->all();
        }

        $tz = BaghdadTime::timezone();

        return $entries->filter(function (AuditLog $entry) use ($from, $to, $ipFilter, $q, $actorsById, $tz) {
            if ($from !== null || $to !== null) {
                $day = $entry->at->setTimezone($tz)->toDateString();
                if ($from !== null && $day < $from) {
                    return false;
                }
                if ($to !== null && $day > $to) {
                    return false;
                }
            }

            if ($ipFilter !== null && ! SearchNormalizer::ipMatches($entry->ip, $ipFilter)) {
                return false;
            }

            if ($q !== '') {
                $actor = $entry->actor_id !== null ? ($actorsById[$entry->actor_id] ?? null) : null;
                $actionWords = self::ACTION_WORDS[$entry->action] ?? ['ar' => '', 'en' => ''];
                $fields = [
                    $actor?->name,
                    $actor?->name_en,
                    $actor?->email,
                    $actionWords['ar'],
                    $actionWords['en'],
                    $entry->target_label,
                    $entry->ip,
                ];
                foreach ($entry->changes ?? [] as $ch) {
                    $fields[] = $ch['field'] ?? null;
                    $fields[] = $ch['from'] ?? null;
                    $fields[] = $ch['to'] ?? null;
                }
                if (! SearchNormalizer::matches($q, ...$fields)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function auditEntry(User $actor, int $id, Request $request): array
    {
        if (! $this->access->canSeeAudit($actor)) {
            throw ApiException::forbidden('api.admin.audit_forbidden');
        }

        $entry = AuditLog::query()->find($id);
        if (! $entry) {
            throw ApiException::notFound('api.admin.audit_not_found');
        }

        $day = BaghdadTime::toBaghdadDate($entry->at);

        $relatedByActor = collect();
        if ($entry->actor_id !== null) {
            $candidates = AuditLog::query()
                ->where('actor_id', $entry->actor_id)
                ->where('id', '!=', $entry->id)
                ->orderByDesc('at')
                ->orderByDesc('id')
                ->get();

            $relatedByActor = $candidates
                ->filter(fn (AuditLog $other) => BaghdadTime::toBaghdadDate($other->at) === $day)
                ->take(self::RELATED_LIMIT)
                ->values();
        }

        $targetQuery = AuditLog::query()
            ->where('id', '!=', $entry->id)
            ->where(function ($q) use ($entry) {
                $q->where(function ($sub) use ($entry) {
                    $sub->where('target_type', $entry->target_type);
                    if ($entry->target_id !== null) {
                        $sub->where('target_id', $entry->target_id);
                    } else {
                        $sub->whereNull('target_id')->where('target_label', $entry->target_label);
                    }
                });

                if ($entry->target_id !== null && in_array($entry->target_type, ['user', 'login'], true)) {
                    $otherType = $entry->target_type === 'user' ? 'login' : 'user';
                    $q->orWhere(function ($sub) use ($otherType, $entry) {
                        $sub->where('target_type', $otherType)->where('target_id', $entry->target_id);
                    });
                }
            })
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit(self::RELATED_LIMIT)
            ->get();

        $base = (new AuditLogResource($entry))->resolve($request);
        $base['related_by_actor'] = AuditLogResource::collection($relatedByActor)->resolve($request);
        $base['related_by_target'] = AuditLogResource::collection($targetQuery)->resolve($request);

        return $base;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveCustomRoleBase(array $input): ?string
    {
        if (! array_key_exists('custom_role', $input) || $input['custom_role'] === null || $input['custom_role'] === '') {
            return null;
        }

        $role = $this->advanced->findRoleByKey((string) $input['custom_role']);
        if ($role === null) {
            throw ApiException::validation('role', 'api.admin.custom_role_not_found');
        }

        return (string) $role['base'];
    }

    private function roleLabel(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $role = $this->advanced->findRoleByKey($key);

        return $role ? (string) ($role['name'] ?? $key) : $key;
    }

    private function requireAdmin(User $actor): void
    {
        if (! $this->access->canAdminister($actor)) {
            throw ApiException::forbidden('api.admin.forbidden');
        }
    }

    private function requireSessionsAdmin(User $actor): void
    {
        if (! $this->access->canManageSessions($actor)) {
            throw ApiException::forbidden('api.admin.sessions_forbidden');
        }
    }

    private function currentSessionId(User $actor): ?string
    {
        $token = $actor->currentAccessToken();

        return ($token instanceof PersonalAccessToken && ! empty($token->session_id))
            ? (string) $token->session_id
            : null;
    }

    /**
     * @param  Collection<int, AuthSession>  $list
     */
    private function endSessions(Collection $list, int $actorId, string $reason): void
    {
        if ($list->isEmpty()) {
            return;
        }

        $now = now('UTC');
        $sessionIds = [];
        $tokenIds = [];

        foreach ($list as $s) {
            $s->forceFill([
                'ended_at' => $now,
                'ended_by' => $actorId,
                'end_reason' => $reason,
            ])->save();
            $sessionIds[] = $s->id;
            if ($s->token_id !== null) {
                $tokenIds[] = (int) $s->token_id;
            }
        }

        if ($tokenIds !== []) {
            PersonalAccessToken::query()->whereIn('id', $tokenIds)->delete();
        }
        $this->push->deleteForSessions($sessionIds);

        /** @var AuthSession $first */
        $first = $list->first();
        $person = User::query()->find($first->user_id);
        $target = [
            'type' => 'user',
            'id' => (int) $first->user_id,
            'label' => $person?->name ?? '-',
        ];

        if ($reason === 'admin') {
            $changes = [
                ['field' => 'session', 'to' => mb_substr($first->id, 0, 12)],
                ['field' => 'device', 'to' => $this->describeDevice($first->user_agent)],
            ];
            if ($first->ip !== null && $first->ip !== '') {
                $changes[] = ['field' => 'ip', 'to' => $first->ip];
            }
            $this->audit->log($actorId, 'session_ended', $target, $changes);
        } elseif ($reason === 'everywhere') {
            $this->audit->log($actorId, 'sessions_ended', $target, [
                ['field' => 'sessions', 'to' => (string) $list->count()],
            ]);
        }
    }

    private function describeDevice(?string $ua): string
    {
        if ($ua === null || trim($ua) === '') {
            return 'Unknown';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Device',
        };

        return "{$os} · {$browser}";
    }

    private function estimateAuditBytes(int $auditCount): int
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $dbName = (string) config('database.connections.'.config('database.default').'.database');
                $row = DB::selectOne(
                    'SELECT (DATA_LENGTH + INDEX_LENGTH) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, 'audit_logs']
                );
                if ($row && isset($row->bytes)) {
                    return (int) $row->bytes;
                }
            }
        } catch (\Throwable) {
            // Fall back to row estimate below.
        }

        return $auditCount * 512;
    }

    /**
     * @return array<string, mixed>
     */
    private function readBackupState(): array
    {
        $path = trim((string) config('services.health.backup_status_path', ''));
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['status'])) {
                return ApiResponse::clean([
                    'at' => $decoded['at'] ?? null,
                    'status' => in_array($decoded['status'], ['ok', 'failed', 'running', 'unknown'], true)
                        ? $decoded['status']
                        : 'unknown',
                    'size_bytes' => isset($decoded['size_bytes']) ? (int) $decoded['size_bytes'] : null,
                    'note' => isset($decoded['note']) ? (string) $decoded['note'] : null,
                ]);
            }
        }

        return [
            'status' => 'unknown',
            'note' => 'Managed by cPanel/hosting schedule.',
        ];
    }

    private function roleFromText(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return 'employee';
        }
        foreach (self::ROLE_WORDS as $key => $w) {
            if ($t === $key || $t === mb_strtolower($w['ar']) || $t === mb_strtolower($w['en'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Team>  $teams
     */
    private function teamFromText(string $text, Collection $teams): ?Team
    {
        $t = mb_strtolower(trim($text));
        foreach ($teams as $team) {
            if (mb_strtolower($team->name) === $t) {
                return $team;
            }
            if ($team->name_en !== null && mb_strtolower($team->name_en) === $t) {
                return $team;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkUser(array $input, ?int $selfId, bool $isCreate): void
    {
        if ($isCreate || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > self::LIMITS['name']) {
                throw ApiException::validation('name', 'api.admin.name_required');
            }
        }

        if ($isCreate || array_key_exists('email', $input)) {
            $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ApiException::validation('email', 'api.admin.email_invalid');
            }
            $exists = User::query()
                ->where('email', $email)
                ->when($selfId !== null, fn ($q) => $q->where('id', '!=', $selfId))
                ->exists();
            if ($exists) {
                throw ApiException::validation('email', 'api.admin.email_taken');
            }
        }

        if ($isCreate || array_key_exists('role', $input)) {
            if (! in_array($input['role'] ?? '', User::ROLES, true)) {
                throw ApiException::validation('role', 'api.admin.role_invalid');
            }
        }

        if (array_key_exists('title', $input) && mb_strlen((string) $input['title']) > self::LIMITS['title']) {
            throw ApiException::validation('title', 'api.admin.title_max');
        }

        if (array_key_exists('team_id', $input) && $input['team_id'] !== null) {
            if (! Team::query()->where('id', (int) $input['team_id'])->exists()) {
                throw ApiException::validation('team_id', 'api.admin.team_not_found');
            }
        }

        if (array_key_exists('birth_date', $input) && $input['birth_date'] !== null && $input['birth_date'] !== '') {
            $problem = Birthday::problem($input['birth_date'], BaghdadTime::today());
            if ($problem !== null) {
                throw ApiException::validation('birth_date', "api.admin.birth_date_{$problem}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkTeam(array $input, ?int $selfId): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::LIMITS['teamName']) {
            throw ApiException::validation('name', 'api.admin.team_name_required');
        }
        $exists = Team::query()
            ->where('name', $name)
            ->when($selfId !== null, fn ($q) => $q->where('id', '!=', $selfId))
            ->exists();
        if ($exists) {
            throw ApiException::validation('name', 'api.admin.team_name_taken');
        }

        if (array_key_exists('lead_id', $input) && $input['lead_id'] !== null) {
            $lead = User::query()->find((int) $input['lead_id']);
            if (! $lead || ! $lead->active || $lead->role !== 'lead') {
                throw ApiException::validation('lead_id', 'api.admin.team_lead_invalid');
            }
        }

        return $name;
    }

    private function joinLead(Team $team, ?int $leadId, int $actorId): void
    {
        if ($leadId === null) {
            return;
        }
        $lead = User::query()->find($leadId);
        if ($lead && (int) $lead->team_id !== (int) $team->id) {
            $lead->team_id = $team->id;
            $lead->save();
            $this->notifications->notify([$lead->id], 'team_joined', [
                'actor_id' => $actorId,
                'data' => ['note' => $team->name],
            ]);
        }
    }
}
