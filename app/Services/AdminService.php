<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\AuditLogResource;
use App\Models\AppSetting;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\LoginSecurity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\BaghdadTime;
use App\Support\SearchNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminService
{
    public const LIMITS = ['name' => 120, 'title' => 80, 'teamName' => 80];

    public const RELATED_LIMIT = 20;

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
        'project_pm_changed' => ['ar' => 'غيّر مدير مشروع', 'en' => "changed a project's PM"],
        'sprint_closed' => ['ar' => 'أنهى دورة', 'en' => 'ended a sprint'],
        'template_deleted' => ['ar' => 'حذف قالباً', 'en' => 'removed a template'],
        'announcement_deleted' => ['ar' => 'حذف إعلاناً', 'en' => 'removed an announcement'],
        'criteria_changed' => ['ar' => 'غيّر معايير التقييم', 'en' => 'changed the review criteria'],
        'criteria_approved' => ['ar' => 'اعتمد معايير التقييم', 'en' => 'approved the review criteria'],
        'review_finalized' => ['ar' => 'اعتمد تقييم أداء', 'en' => 'made a review final'],
        'picture_removed' => ['ar' => 'أزال صورة شخصية', 'en' => 'removed a profile picture'],
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

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
     * @return array<string, int>
     */
    public function health(User $actor): array
    {
        $this->requireAdmin($actor);
        $now = now('UTC');

        return [
            'users_active' => User::query()->where('active', true)->count(),
            'users_stopped' => User::query()->where('active', false)->count(),
            'users_locked' => LoginSecurity::query()->whereNotNull('locked_until')->where('locked_until', '>', $now)->count(),
            'projects' => Project::query()->count(),
            'tasks' => Task::query()->count(),
            'files' => Attachment::query()->count(),
            'files_bytes' => (int) Attachment::query()->sum('size'),
            'audit_entries' => AuditLog::query()->count(),
        ];
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
