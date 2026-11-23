<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AdvancedSetting;
use App\Models\AdvancedSettingsLog;
use App\Models\AppSetting;
use App\Models\Notification;
use App\Models\User;
use App\Support\BaghdadTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdvancedSettingsService
{
    public const HISTORY_KEPT = 300;

    public const FEATURE_KEYS = ['chat', 'group_chats', 'voice_messages', 'bugs', 'reviews', 'announcements', 'requests', 'github', 'welcome'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const APPROVERS = ['pm', 'lead', 'either'];

    public const BASE_JOBS = ['pm', 'monitor', 'lead', 'employee'];

    public const STAFF_ROLES = ['pm', 'monitor', 'lead', 'employee', 'admin'];

    public const ROLE_COLORS = ['todo', 'progress', 'review', 'testing', 'done', 'new'];

    public const ACTION_DEFS = [
        'create_project' => ['pm', 'monitor'],
        'close_project' => ['pm'],
        'delete_task' => ['pm', 'lead'],
        'reassign_task' => ['pm', 'lead'],
        'change_due' => ['pm', 'lead'],
        'approve_done' => ['pm', 'lead'],
        'post_announcement' => ['monitor', 'admin'],
        'create_group_chat' => ['pm', 'monitor', 'lead'],
        'see_scores' => ['pm', 'monitor', 'lead'],
        'write_reviews' => ['pm', 'lead'],
        'give_ratings' => ['pm', 'monitor'],
        'see_reports' => ['pm', 'monitor', 'lead'],
        'export_csv' => ['pm', 'monitor', 'lead', 'admin'],
        'report_bugs' => ['pm', 'monitor', 'lead', 'employee'],
        'bug_severity' => ['pm', 'lead'],
    ];

    public const NAV_ROLES = [
        'dashboard' => ['ceo', 'pm', 'monitor', 'lead'],
        'my-tasks' => ['pm', 'lead', 'employee'],
        'projects' => ['ceo', 'pm', 'monitor', 'lead', 'employee'],
        'chat' => ['ceo', 'pm', 'monitor', 'lead', 'employee'],
        'bugs' => ['ceo', 'pm', 'monitor', 'lead', 'employee'],
        'quick-actions' => ['monitor'],
        'announcements' => ['ceo', 'pm', 'monitor', 'lead', 'employee', 'admin'],
        'performance' => ['ceo', 'pm', 'monitor', 'lead', 'employee'],
        'teams' => ['ceo', 'pm', 'monitor'],
        'reports' => ['ceo', 'pm', 'monitor', 'lead'],
        'monitoring' => ['monitor'],
        'inbox' => ['ceo'],
        'requests' => ['pm', 'monitor', 'lead', 'employee', 'admin'],
        'admin' => ['admin'],
        'audit' => ['ceo', 'admin'],
        'advanced' => ['ceo'],
    ];

    private const LIMITS = [
        'due_days' => [1, 90],
        'fix_days' => [1, 60],
        'wip_limit' => [0, 50],
        'password_min' => [6, 32],
        'holidays' => 60,
        'holiday_name' => 60,
        'banner' => 300,
        'read_only' => 300,
        'roles' => 20,
        'role_name' => 40,
        'role_description' => 200,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function defaultRules(): array
    {
        $notify = [];
        foreach (Notification::KINDS as $kind) {
            $notify[$kind] = User::ROLES;
        }

        $features = [];
        foreach (self::FEATURE_KEYS as $f) {
            $features[$f] = true;
        }

        return [
            'work' => ['days' => [0, 1, 2, 3, 4], 'from' => '08:00', 'to' => '16:00', 'holidays' => []],
            'due_days' => ['high' => 3, 'medium' => 7, 'low' => 14],
            'fix_days' => ['critical' => 1, 'high' => 3, 'medium' => 7, 'low' => 14],
            'tasks' => ['approver' => 'either', 'employee_reopen' => true, 'due_reason' => false, 'wip_limit' => 0],
            'permissions' => self::ACTION_DEFS,
            'notify' => $notify,
            'features' => $features,
            'security' => ['due_soon_days' => 1, 'lock_after' => 5, 'lock_minutes' => 15, 'password_min' => 8],
            'names' => ['task' => 'TSK', 'project' => 'PRJ', 'bug' => 'BUG'],
            'banner' => null,
            'read_only' => ['on' => false, 'message' => ''],
            'roles' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, int>|null  $adminSecurity
     * @return array<string, mixed>
     */
    public function rulesOf(?array $stored = null, ?array $adminSecurity = null): array
    {
        $s = $stored ?? [];
        $d = $this->defaultRules();

        return [
            'work' => array_merge($d['work'], is_array($s['work'] ?? null) ? $s['work'] : []),
            'due_days' => array_merge($d['due_days'], is_array($s['due_days'] ?? null) ? $s['due_days'] : []),
            'fix_days' => array_merge($d['fix_days'], is_array($s['fix_days'] ?? null) ? $s['fix_days'] : []),
            'tasks' => array_merge($d['tasks'], is_array($s['tasks'] ?? null) ? $s['tasks'] : []),
            'permissions' => array_merge($d['permissions'], is_array($s['permissions'] ?? null) ? $s['permissions'] : []),
            'notify' => array_merge($d['notify'], is_array($s['notify'] ?? null) ? $s['notify'] : []),
            'features' => array_merge($d['features'], is_array($s['features'] ?? null) ? $s['features'] : []),
            'security' => array_merge(
                $d['security'],
                is_array($s['security'] ?? null) ? $s['security'] : [],
                $adminSecurity ?? []
            ),
            'names' => array_merge($d['names'], is_array($s['names'] ?? null) ? $s['names'] : []),
            'banner' => array_key_exists('banner', $s) ? $s['banner'] : $d['banner'],
            'read_only' => array_merge($d['read_only'], is_array($s['read_only'] ?? null) ? $s['read_only'] : []),
            'roles' => array_key_exists('roles', $s) && is_array($s['roles']) ? array_values($s['roles']) : $d['roles'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function currentRules(): array
    {
        $row = AdvancedSetting::query()->find(1);
        $admin = AppSetting::current();

        return $this->rulesOf($row?->rules ?? [], [
            'due_soon_days' => (int) $admin->due_soon_days,
            'lock_after' => (int) $admin->lock_after,
            'lock_minutes' => (int) $admin->lock_minutes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor): array
    {
        $this->requireCeo($actor);

        return $this->answer();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(User $actor, array $payload): array
    {
        $this->requireCeo($actor);

        $how = $payload['how'] ?? null;
        if ($how !== null && ! in_array($how, ['import', 'reset'], true)) {
            throw new ApiException(422, 'VALIDATION', __('api.advanced.invalid_how'));
        }

        $sent = $payload['rules'] ?? null;
        if ($how !== 'reset' && (! is_array($sent) || array_is_list($sent) && $sent !== [])) {
            throw new ApiException(422, 'VALIDATION', __('api.advanced.invalid_payload'));
        }

        $next = $this->rulesOf($how === 'reset' ? [] : ($sent ?? []));
        $problems = $this->checkRules($next);
        if ($problems !== []) {
            $isAr = app()->getLocale() === 'ar';
            $errors = [];
            foreach ($problems as $k => $msgs) {
                $errors[$k] = $isAr ? $msgs['ar'] : $msgs['en'];
            }
            throw new ApiException(422, 'VALIDATION', __('api.advanced.fix_errors'), $errors);
        }

        $before = $this->currentRules();
        $changes = $this->diffRules($before, $next);
        if ($changes === []) {
            return $this->answer();
        }

        foreach ($next['roles'] as $role) {
            foreach ($before['roles'] as $prev) {
                if ($prev['key'] === $role['key'] && $prev['base'] !== $role['base']) {
                    $msg = __('api.advanced.base_immutable');
                    throw new ApiException(422, 'VALIDATION', $msg, ["roles.{$role['key']}.base" => $msg]);
                }
            }
        }

        $nextRoleKeys = array_column($next['roles'], 'key');
        $goneRoles = array_values(array_filter(
            $before['roles'],
            fn (array $r) => ! in_array($r['key'], $nextRoleKeys, true)
        ));

        return DB::transaction(function () use ($actor, $next, $changes, $goneRoles, $how) {
            if ($goneRoles !== [] && $how === null) {
                $inUse = [];
                foreach ($goneRoles as $role) {
                    $count = User::query()
                        ->where('active', true)
                        ->where('custom_role', $role['key'])
                        ->count();
                    if ($count > 0) {
                        $label = app()->getLocale() === 'ar' ? "«{$role['name']}» ({$count})" : "\"{$role['name_en']}\" ({$count})";
                        $inUse[] = $label;
                    }
                }
                if ($inUse !== []) {
                    $sep = app()->getLocale() === 'ar' ? '، ' : ', ';
                    throw ApiException::badState('api.advanced.role_in_use', ['list' => implode($sep, $inUse)]);
                }
            }

            if ($goneRoles !== []) {
                $goneKeys = array_column($goneRoles, 'key');
                User::query()->whereIn('custom_role', $goneKeys)->update(['custom_role' => null]);
            }

            $sec = $next['security'];
            $adminSetting = AppSetting::current();
            $adminSetting->due_soon_days = (int) $sec['due_soon_days'];
            $adminSetting->lock_after = (int) $sec['lock_after'];
            $adminSetting->lock_minutes = (int) $sec['lock_minutes'];
            $adminSetting->save();

            $stored = $this->changedRules($next);
            $defaultPasswordMin = (int) ($this->defaultRules()['security']['password_min']);
            if ((int) $sec['password_min'] !== $defaultPasswordMin) {
                $stored['security'] = $sec;
            } else {
                unset($stored['security']);
            }

            $now = now('UTC');
            AdvancedSetting::query()->updateOrCreate(
                ['id' => 1],
                [
                    'rules' => $stored,
                    'updated_by' => $actor->id,
                    'updated_at' => $now,
                ]
            );

            AdvancedSettingsLog::create([
                'at' => $now,
                'by_id' => $actor->id,
                'by_name' => $actor->name,
                'by_name_en' => $actor->name_en,
                'how' => $how,
                'changes' => $changes,
            ]);

            $keepIds = AdvancedSettingsLog::query()->orderByDesc('id')->limit(self::HISTORY_KEPT)->pluck('id')->all();
            if ($keepIds !== []) {
                AdvancedSettingsLog::query()->whereNotIn('id', $keepIds)->delete();
            }

            $auditChanges = array_map(fn (array $c) => [
                'field' => $c['key'],
                'from' => $this->shortValue($c['from']),
                'to' => $this->shortValue($c['to']),
            ], $changes);

            $this->audit->log($actor->id, 'settings_changed', [
                'type' => 'settings',
                'label' => 'الإعدادات المتقدمة',
            ], $auditChanges);

            return $this->answer();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(User $actor): array
    {
        $this->requireCeo($actor);

        return AdvancedSettingsLog::query()
            ->orderByDesc('id')
            ->limit(self::HISTORY_KEPT)
            ->get()
            ->map(function (AdvancedSettingsLog $log) {
                $by = ['id' => (int) $log->by_id, 'name' => $log->by_name];
                if ($log->by_name_en !== null && $log->by_name_en !== '') {
                    $by['name_en'] = $log->by_name_en;
                }

                $entry = [
                    'id' => (int) $log->id,
                    'at' => BaghdadTime::iso($log->at),
                    'by' => $by,
                    'changes' => $log->changes,
                ];
                if ($log->how !== null) {
                    $entry['how'] = $log->how;
                }

                return $entry;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function liveFor(User $user): array
    {
        $rules = $this->currentRules();
        $banner = $rules['banner'];
        $forMe = false;

        if (is_array($banner)) {
            $roles = $banner['roles'] ?? [];
            $roleMatch = $roles === [] || in_array($user->role, $roles, true);
            $until = $banner['until'] ?? null;
            $notExpired = true;
            if (is_string($until) && $until !== '') {
                try {
                    $notExpired = CarbonImmutable::parse($until)->gt(now('UTC'));
                } catch (\Throwable) {
                    $notExpired = false;
                }
            }
            $forMe = $roleMatch && $notExpired;
        }

        $me = ['role' => $user->role];
        if ($user->custom_role !== null && $user->custom_role !== '') {
            $me['custom_role'] = $user->custom_role;
        }

        return [
            'features' => $rules['features'],
            'banner' => $forMe ? $banner : null,
            'read_only' => $rules['read_only'],
            'roles' => $rules['roles'],
            'me' => $me,
            'password_min' => (int) ($rules['security']['password_min'] ?? 8),
        ];
    }

    public function passwordMin(): int
    {
        return (int) ($this->currentRules()['security']['password_min'] ?? 8);
    }

    public function featureIsOn(string $featureKey): bool
    {
        $rules = $this->currentRules();

        return ($rules['features'][$featureKey] ?? true) !== false;
    }

    public function requireFeature(User $user, string $featureKey): void
    {
        if ($user->isCeo() || $this->featureIsOn($featureKey)) {
            return;
        }

        throw new ApiException(403, 'FEATURE_OFF', __('api.advanced.feature_off'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function customRoleFor(User $user): ?array
    {
        if ($user->custom_role === null || $user->custom_role === '') {
            return null;
        }

        return $this->findRoleByKey($user->custom_role);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRoleByKey(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        $rules = $this->currentRules();
        foreach ($rules['roles'] as $role) {
            if (($role['key'] ?? '') === $key) {
                return $role;
            }
        }

        return null;
    }

    public function roleMay(User $user, string $action): bool
    {
        if ($user->isCeo()) {
            return true;
        }
        $role = $this->customRoleFor($user);
        if ($role === null) {
            return true;
        }

        return in_array($action, $role['actions'] ?? [], true);
    }

    public function requireAction(User $user, string $action): void
    {
        if ($this->roleMay($user, $action)) {
            return;
        }
        $role = $this->customRoleFor($user);
        $name = app()->getLocale() === 'ar'
            ? ($role['name'] ?? '')
            : (($role['name_en'] ?? '') ?: ($role['name'] ?? ''));

        throw new ApiException(403, 'FORBIDDEN', __('api.advanced.custom_role_forbidden', ['role' => $name]));
    }

    public function codePrefix(string $kind): string
    {
        $rules = $this->currentRules();

        return (string) ($rules['names'][$kind] ?? strtoupper(substr($kind, 0, 3)));
    }

    public function jobGets(string $role, string $kind): bool
    {
        if (in_array($kind, Notification::REQUIRED_KINDS, true)) {
            return true;
        }
        $rules = $this->currentRules();
        $row = $rules['notify'][$kind] ?? null;

        return ! is_array($row) || in_array($role, $row, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readOnlyStatus(): ?array
    {
        $rules = $this->currentRules();
        $ro = $rules['read_only'] ?? ['on' => false, 'message' => ''];

        return ! empty($ro['on']) ? $ro : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $last = AdvancedSettingsLog::query()->orderByDesc('id')->first();
        $out = ['rules' => $this->currentRules()];
        if ($last) {
            $out['updated_at'] = BaghdadTime::iso($last->at);
            $out['updated_by'] = ['id' => (int) $last->by_id, 'name' => $last->by_name];
        }

        return $out;
    }

    private function requireCeo(User $actor): void
    {
        if (! $actor->isCeo()) {
            throw ApiException::forbidden('api.advanced.forbidden');
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function changedRules(array $rules): array
    {
        $defaults = $this->defaultRules();
        $out = [];
        foreach ($defaults as $k => $defVal) {
            if (json_encode($rules[$k] ?? null) !== json_encode($defVal)) {
                $out[$k] = $rules[$k];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, array{key: string, from: mixed, to: mixed}>
     */
    private function diffRules(array $before, array $after): array
    {
        $out = [];
        $defaults = $this->defaultRules();

        foreach (array_keys($defaults) as $k) {
            $a = $before[$k] ?? null;
            $b = $after[$k] ?? null;

            if ($k === 'roles') {
                $was = [];
                foreach ((array) $a as $r) {
                    $was[$r['key']] = $r;
                }
                $now = [];
                foreach ((array) $b as $r) {
                    $now[$r['key']] = $r;
                }
                foreach (array_unique(array_merge(array_keys($was), array_keys($now))) as $rKey) {
                    $x = $was[$rKey] ?? null;
                    $y = $now[$rKey] ?? null;
                    if (json_encode($this->normRole($x)) !== json_encode($this->normRole($y))) {
                        $out[] = ['key' => "roles.{$rKey}", 'from' => $x, 'to' => $y];
                    }
                }
            } elseif (is_array($a) && is_array($b) && ! array_is_list($a) && ! array_is_list($b)) {
                foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $sub) {
                    $va = $this->normSet($a[$sub] ?? null);
                    $vb = $this->normSet($b[$sub] ?? null);
                    if (json_encode($va) !== json_encode($vb)) {
                        $out[] = ['key' => "{$k}.{$sub}", 'from' => $a[$sub] ?? null, 'to' => $b[$sub] ?? null];
                    }
                }
            } elseif (json_encode($a) !== json_encode($b)) {
                $out[] = ['key' => $k, 'from' => $a, 'to' => $b];
            }
        }

        return $out;
    }

    private function normSet(mixed $v): mixed
    {
        if (is_array($v) && array_is_list($v)) {
            $copy = $v;
            if (array_reduce($copy, fn ($carry, $item) => $carry && (is_string($item) || is_int($item)), true)) {
                sort($copy);
            }

            return $copy;
        }

        return $v;
    }

    /**
     * @param  array<string, mixed>|null  $role
     * @return array<string, mixed>|null
     */
    private function normRole(?array $role): ?array
    {
        if ($role === null) {
            return null;
        }
        $copy = $role;
        $copy['actions'] = array_values((array) ($copy['actions'] ?? []));
        $copy['pages'] = array_values((array) ($copy['pages'] ?? []));
        sort($copy['actions']);
        sort($copy['pages']);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, array{ar: string, en: string}>
     */
    private function checkRules(array $r): array
    {
        $e = [];
        $w = $r['work'];
        $days = $w['days'] ?? null;
        if (! is_array($days) || $this->hasBadInt($days, 0, 6) || count(array_unique($days)) !== count($days)) {
            $e['work.days'] = ['ar' => 'أيام غير صحيحة', 'en' => 'Wrong days'];
        } elseif ($days === []) {
            $e['work.days'] = ['ar' => 'اختر يوم عمل واحداً على الأقل', 'en' => 'Choose at least one working day'];
        }

        $from = $w['from'] ?? '';
        $to = $w['to'] ?? '';
        if (! $this->isTime($from) || ! $this->isTime($to) || $this->minutes($from) >= $this->minutes($to)) {
            $e['work.hours'] = ['ar' => 'البداية قبل النهاية', 'en' => 'The start comes before the end'];
        }

        $holidays = $w['holidays'] ?? null;
        if (! is_array($holidays) || count($holidays) > self::LIMITS['holidays']) {
            $e['work.holidays'] = ['ar' => 'حتى '.self::LIMITS['holidays'].' عطلة', 'en' => 'Up to '.self::LIMITS['holidays'].' holidays'];
        } else {
            $dates = [];
            foreach ($holidays as $h) {
                $hDate = $h['date'] ?? '';
                $hName = trim((string) ($h['name'] ?? ''));
                $hNameEn = (string) ($h['name_en'] ?? '');
                if (! $this->isDay($hDate) || $hName === '' || mb_strlen($hName) > self::LIMITS['holiday_name'] || mb_strlen($hNameEn) > self::LIMITS['holiday_name']) {
                    $e['work.holidays'] = ['ar' => 'لكل عطلة تاريخ واسم', 'en' => 'Each holiday needs a date and a name'];
                    break;
                }
                if (in_array($hDate, $dates, true)) {
                    $e['work.holidays'] = ['ar' => 'عطلتان في اليوم نفسه', 'en' => 'Two holidays on the same day'];
                    break;
                }
                $dates[] = $hDate;
            }
        }

        foreach (self::PRIORITIES as $p) {
            if (! $this->whole($r['due_days'][$p] ?? null, self::LIMITS['due_days'])) {
                $e["due_days.{$p}"] = $this->rangeMsg(self::LIMITS['due_days']);
            }
        }

        foreach (self::SEVERITIES as $s) {
            if (! $this->whole($r['fix_days'][$s] ?? null, self::LIMITS['fix_days'])) {
                $e["fix_days.{$s}"] = $this->rangeMsg(self::LIMITS['fix_days']);
            }
        }

        $sevWords = [
            'critical' => ['ar' => 'حرجة', 'en' => 'critical'],
            'high' => ['ar' => 'عالية', 'en' => 'high'],
            'medium' => ['ar' => 'متوسطة', 'en' => 'medium'],
            'low' => ['ar' => 'منخفضة', 'en' => 'low'],
        ];
        for ($i = 1; $i < count(self::SEVERITIES); $i++) {
            $worse = self::SEVERITIES[$i - 1];
            $lighter = self::SEVERITIES[$i];
            if (! isset($e["fix_days.{$lighter}"]) && ! isset($e["fix_days.{$worse}"]) && $r['fix_days'][$lighter] < $r['fix_days'][$worse]) {
                $e["fix_days.{$lighter}"] = [
                    'ar' => "لا يقل عن «{$sevWords[$worse]['ar']}»",
                    'en' => "Not less than {$sevWords[$worse]['en']}",
                ];
            }
        }

        $tasks = $r['tasks'];
        if (! in_array($tasks['approver'] ?? '', self::APPROVERS, true)) {
            $e['tasks.approver'] = ['ar' => 'اختر واحداً', 'en' => 'Choose one'];
        }
        if (! is_bool($tasks['employee_reopen'] ?? null)) {
            $e['tasks.employee_reopen'] = ['ar' => 'نعم أو لا', 'en' => 'Yes or no'];
        }
        if (! is_bool($tasks['due_reason'] ?? null)) {
            $e['tasks.due_reason'] = ['ar' => 'نعم أو لا', 'en' => 'Yes or no'];
        }
        if (! $this->whole($tasks['wip_limit'] ?? null, self::LIMITS['wip_limit'])) {
            $e['tasks.wip_limit'] = ['ar' => 'من 0 (بلا حد) إلى 50', 'en' => '0 (no limit) to 50'];
        }

        foreach ($r['permissions'] as $k => $roles) {
            if (! array_key_exists($k, self::ACTION_DEFS) || ! is_array($roles) || array_diff($roles, self::STAFF_ROLES) !== []) {
                $e["permissions.{$k}"] = ['ar' => 'وظائف غير صحيحة', 'en' => 'Wrong jobs'];
            }
        }

        foreach ($r['notify'] as $k => $roles) {
            if (! in_array($k, Notification::KINDS, true) || ! is_array($roles) || array_diff($roles, User::ROLES) !== []) {
                $e["notify.{$k}"] = ['ar' => 'وظائف غير صحيحة', 'en' => 'Wrong jobs'];
            } elseif (in_array($k, Notification::REQUIRED_KINDS, true) && array_diff(User::ROLES, $roles) !== []) {
                $e["notify.{$k}"] = ['ar' => 'إشعار مطلوب: يصل الجميع', 'en' => 'A required notification: it reaches everyone'];
            }
        }

        foreach ($r['features'] as $k => $v) {
            if (! in_array($k, self::FEATURE_KEYS, true) || ! is_bool($v)) {
                $e["features.{$k}"] = ['ar' => 'تشغيل أو إيقاف', 'en' => 'On or off'];
            }
        }

        foreach (AdminService::SETTING_RANGES as $k => $limits) {
            if (! $this->whole($r['security'][$k] ?? null, $limits)) {
                $e["security.{$k}"] = $this->rangeMsg($limits);
            }
        }
        if (! $this->whole($r['security']['password_min'] ?? null, self::LIMITS['password_min'])) {
            $e['security.password_min'] = $this->rangeMsg(self::LIMITS['password_min']);
        }

        $seenCodes = [];
        foreach (['task', 'project', 'bug'] as $codeKind) {
            $val = $r['names'][$codeKind] ?? null;
            if (! is_string($val) || preg_match('/^[A-Z]{2,5}$/', $val) !== 1) {
                $e["names.{$codeKind}"] = ['ar' => 'من 2 إلى 5 أحرف إنجليزية كبيرة', 'en' => '2 to 5 capital letters A to Z'];
            } elseif (isset($seenCodes[$val])) {
                $e["names.{$codeKind}"] = ['ar' => 'مستعملة لنوع آخر', 'en' => 'Used by another kind'];
            } else {
                $seenCodes[$val] = $codeKind;
            }
        }

        $b = $r['banner'];
        if ($b !== null) {
            $bText = is_array($b) ? (string) ($b['text'] ?? '') : '';
            $bTextEn = is_array($b) ? (string) ($b['text_en'] ?? '') : '';
            $bRoles = is_array($b) ? ($b['roles'] ?? null) : null;
            $bTone = is_array($b) ? ($b['tone'] ?? '') : '';
            $bUntil = is_array($b) ? ($b['until'] ?? null) : null;
            if (trim($bText) === '' || mb_strlen($bText) > self::LIMITS['banner']) {
                $e['banner.text'] = ['ar' => 'اكتب نص الإعلان (حتى '.self::LIMITS['banner'].' حرف)', 'en' => 'Write the banner (up to '.self::LIMITS['banner'].' letters)'];
            }
            if (mb_strlen($bTextEn) > self::LIMITS['banner']) {
                $e['banner.text_en'] = ['ar' => 'حتى '.self::LIMITS['banner'].' حرف', 'en' => 'Up to '.self::LIMITS['banner'].' letters'];
            }
            if (! is_array($bRoles) || array_diff($bRoles, User::ROLES) !== []) {
                $e['banner.roles'] = ['ar' => 'وظائف غير صحيحة', 'en' => 'Wrong jobs'];
            }
            if (! in_array($bTone, ['info', 'warning'], true)) {
                $e['banner.tone'] = ['ar' => 'اختر لوناً', 'en' => 'Choose a colour'];
            }
            if ($bUntil !== null && $bUntil !== '' && strtotime((string) $bUntil) === false) {
                $e['banner.until'] = ['ar' => 'تاريخ غير صحيح', 'en' => 'Not a valid date'];
            }
        }

        $ro = $r['read_only'];
        if (! is_bool($ro['on'] ?? null)) {
            $e['read_only.on'] = ['ar' => 'تشغيل أو إيقاف', 'en' => 'On or off'];
        }
        if (($ro['on'] ?? false) === true && trim((string) ($ro['message'] ?? '')) === '') {
            $e['read_only.message'] = ['ar' => 'اكتب رسالة للموظفين', 'en' => 'Write a message for the staff'];
        }
        if (mb_strlen((string) ($ro['message'] ?? '')) > self::LIMITS['read_only'] || mb_strlen((string) ($ro['message_en'] ?? '')) > self::LIMITS['read_only']) {
            $e['read_only.message'] = ['ar' => 'حتى '.self::LIMITS['read_only'].' حرف', 'en' => 'Up to '.self::LIMITS['read_only'].' letters'];
        }

        return array_merge($e, $this->checkRoles($r['roles']));
    }

    /**
     * @param  mixed  $roles
     * @return array<string, array{ar: string, en: string}>
     */
    private function checkRoles(mixed $roles): array
    {
        $e = [];
        if (! is_array($roles)) {
            return ['roles' => ['ar' => 'أدوار غير صحيحة', 'en' => 'Wrong roles']];
        }
        if (count($roles) > self::LIMITS['roles']) {
            $e['roles'] = ['ar' => 'حتى '.self::LIMITS['roles'].' دوراً', 'en' => 'Up to '.self::LIMITS['roles'].' roles'];
        }

        $jobNames = array_map(fn ($s) => mb_strtolower(trim($s)), [
            'المدير التنفيذي', 'CEO', 'مدير القسم', 'Project manager',
            'مسؤول المتابعة والتقييم', 'Monitoring & evaluation', 'قائد الفريق', 'Team leader',
            'موظف', 'Employee', 'مسؤول النظام', 'System admin',
        ]);

        $seenKeys = [];
        $seenNames = [];
        foreach ($roles as $role) {
            if (! is_array($role)) {
                $e['roles'] = ['ar' => 'أدوار غير صحيحة', 'en' => 'Wrong roles'];

                continue;
            }
            $key = (string) ($role['key'] ?? '');
            $at = fn (string $f) => "roles.{$key}.{$f}";

            if (preg_match('/^[a-z0-9-]{2,40}$/', $key) !== 1 || isset($seenKeys[$key])) {
                $e[$at('key')] = ['ar' => 'مفتاح غير صحيح', 'en' => 'Wrong key'];
            }
            $seenKeys[$key] = true;

            foreach (['name', 'name_en'] as $field) {
                $val = is_string($role[$field] ?? null) ? trim($role[$field]) : '';
                $folded = mb_strtolower($val);
                if ($val === '' || mb_strlen($val) > self::LIMITS['role_name']) {
                    $e[$at($field)] = ['ar' => 'اكتب الاسم (حتى '.self::LIMITS['role_name'].' حرفاً)', 'en' => 'Write the name (up to '.self::LIMITS['role_name'].' letters)'];
                } elseif (in_array($folded, $jobNames, true)) {
                    $e[$at($field)] = ['ar' => 'اسم وظيفة موجودة', 'en' => "That's the name of an existing job"];
                } elseif (isset($seenNames[$folded]) && $seenNames[$folded] !== $key) {
                    $e[$at($field)] = ['ar' => 'مستعمل لدور آخر', 'en' => 'Used by another role'];
                } else {
                    $seenNames[$folded] = $key;
                }
            }

            if (mb_strlen((string) ($role['description'] ?? '')) > self::LIMITS['role_description']) {
                $e[$at('description')] = ['ar' => 'حتى '.self::LIMITS['role_description'].' حرف', 'en' => 'Up to '.self::LIMITS['role_description'].' letters'];
            }
            if (! in_array($role['color'] ?? '', self::ROLE_COLORS, true)) {
                $e[$at('color')] = ['ar' => 'اختر لوناً', 'en' => 'Choose a colour'];
            }
            $base = (string) ($role['base'] ?? '');
            if (! in_array($base, self::BASE_JOBS, true)) {
                $e[$at('base')] = ['ar' => 'يبدأ مثل: مدير قسم أو متابعة أو قائد فريق أو موظف', 'en' => 'Starts like: a PM, monitoring, a team leader or an employee'];

                continue;
            }

            $actions = $role['actions'] ?? null;
            if (! is_array($actions) || array_filter($actions, fn ($a) => ! in_array($base, self::ACTION_DEFS[$a] ?? [], true)) !== []) {
                $e[$at('actions')] = ['ar' => 'لا يأخذ أكثر مما لوظيفته الأساسية', 'en' => 'Never more than its base job'];
            }

            $basePages = array_keys(array_filter(self::NAV_ROLES, fn ($allowed) => in_array($base, $allowed, true)));
            $pages = $role['pages'] ?? null;
            if (! is_array($pages) || array_diff($pages, $basePages) !== []) {
                $e[$at('pages')] = ['ar' => 'صفحات لا تراها وظيفته الأساسية', 'en' => "Pages its base job doesn't see"];
            }
        }

        return $e;
    }

    private function whole(mixed $v, array $range): bool
    {
        return is_int($v) && $v >= $range[0] && $v <= $range[1];
    }

    private function hasBadInt(array $arr, int $lo, int $hi): bool
    {
        foreach ($arr as $v) {
            if (! $this->whole($v, [$lo, $hi])) {
                return true;
            }
        }

        return false;
    }

    private function isTime(mixed $t): bool
    {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    }

    private function minutes(string $t): int
    {
        return ((int) substr($t, 0, 2)) * 60 + ((int) substr($t, 3, 2));
    }

    private function isDay(mixed $d): bool
    {
        if (! is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            return false;
        }
        [$y, $m, $day] = array_map('intval', explode('-', $d));

        return checkdate($m, $day, $y);
    }

    /**
     * @param  array{0: int, 1: int}  $range
     * @return array{ar: string, en: string}
     */
    private function rangeMsg(array $range): array
    {
        return ['ar' => "من {$range[0]} إلى {$range[1]}", 'en' => "{$range[0]} to {$range[1]}"];
    }

    private function shortValue(mixed $v): string
    {
        if (is_array($v) && isset($v['name'], $v['base'])) {
            $s = (string) $v['name'];
        } elseif ($v === null) {
            $s = '-';
        } elseif (is_string($v)) {
            $s = $v;
        } else {
            $s = (string) json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return mb_strlen($s) > 80 ? mb_substr($s, 0, 80).'…' : $s;
    }
}
