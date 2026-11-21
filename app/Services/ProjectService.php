<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\ProjectPhase;
use App\Models\ProjectStatus;
use App\Models\ProjectTemplate;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\BaghdadTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectService
{
    public const LIMITS = [
        'name' => 120,
        'description' => 5000,
        'note' => 300,
        'statusName' => 40,
        'statuses' => 15,
        'phaseName' => 80,
        'phases' => 20,
        'sprintName' => 60,
        'templateName' => 80,
        'templateTasks' => 200,
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly CodeGenerator $codes,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return Collection<int, Project>
     */
    public function listForUser(User $user): Collection
    {
        $today = BaghdadTime::today();
        $projects = Project::query()
            ->with(['members:id', 'statuses', 'phases', 'tasks:id,project_id,status,due'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
            ->values();

        foreach ($projects as $project) {
            $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
            $blockedKeys = $project->statuses->where('tone', 'blocked')->pluck('key')->all();

            $tasks = $project->tasks;
            $project->tasks_total = $tasks->count();
            $project->tasks_done = $tasks->filter(fn (Task $t) => in_array($t->status, $doneKeys, true))->count();
            $project->tasks_blocked = $tasks->filter(fn (Task $t) => in_array($t->status, $blockedKeys, true))->count();
            $project->tasks_late = $tasks->filter(function (Task $t) use ($doneKeys, $today) {
                return $t->due !== null && $t->due < $today && ! in_array($t->status, $doneKeys, true);
            })->count();
        }

        return $projects;
    }

    public function show(User $user, int $projectId): Project
    {
        return $this->access->projectForOrFail($user, $projectId);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Project
    {
        if (! $this->access->canCreateProject($user)) {
            throw ApiException::forbidden('api.projects.create_forbidden');
        }
        $this->advanced->requireAction($user, 'create_project');

        $pmId = $user->role === 'pm' ? (int) $user->id : (int) ($input['pm_id'] ?? 0);
        $fields = array_merge($input, ['pm_id' => $pmId]);

        if (empty($fields['start'])) {
            throw ApiException::validation('start', 'api.projects.start_required');
        }
        if (empty($fields['end'])) {
            throw ApiException::validation('end', 'api.projects.end_required');
        }

        $this->checkFields($fields);

        $templateId = $fields['template_id'] ?? null;
        $template = null;
        if ($templateId !== null) {
            $template = ProjectTemplate::query()->find((int) $templateId);
            if (! $template) {
                throw ApiException::validation('template_id', 'api.projects.template_not_found');
            }
        }

        return DB::transaction(function () use ($user, $fields, $pmId, $template) {
            $today = BaghdadTime::today();
            $memberIds = $this->withPm(array_map('intval', $fields['member_ids'] ?? []), $pmId);

            $project = Project::create([
                'code' => $this->codes->nextProjectCode(),
                'name' => trim((string) $fields['name']),
                'name_en' => isset($fields['name_en']) && trim((string) $fields['name_en']) !== '' ? trim((string) $fields['name_en']) : null,
                'description' => trim((string) ($fields['description'] ?? '')),
                'pm_id' => $pmId,
                'start' => $fields['start'],
                'end' => $fields['end'],
                'state' => $fields['start'] > $today ? 'planned' : 'active',
                'priority' => $fields['priority'] ?? 'medium',
                'uses_sprints' => (bool) ($fields['uses_sprints'] ?? false),
            ]);

            $project->members()->attach($memberIds);

            $statuses = $template ? $template->statuses : ProjectStatus::DEFAULT_STATUSES;
            foreach ($statuses as $pos => $st) {
                ProjectStatus::create([
                    'project_id' => $project->id,
                    'key' => $st['key'],
                    'name' => $st['name'],
                    'name_en' => $st['name_en'],
                    'tone' => $st['tone'],
                    'position' => $pos,
                    'is_base' => in_array($st['key'], ProjectStatus::BASE_KEYS, true),
                ]);
            }

            if ($template) {
                $phaseMap = [];
                foreach ($template->phases ?? [] as $pos => $phaseName) {
                    $ph = ProjectPhase::create([
                        'project_id' => $project->id,
                        'name' => $phaseName,
                        'position' => $pos,
                    ]);
                    $phaseMap[$phaseName] = $ph->id;
                }

                $firstStatusKey = $statuses[0]['key'];
                $now = now('UTC');
                foreach ($template->tasks ?? [] as $tplTask) {
                    $task = Task::create([
                        'code' => $this->codes->nextTaskCode(),
                        'project_id' => $project->id,
                        'phase_id' => isset($tplTask['phase']) ? ($phaseMap[$tplTask['phase']] ?? null) : null,
                        'title' => $tplTask['title'],
                        'description' => $tplTask['description'] ?? '',
                        'priority' => $tplTask['priority'] ?? 'medium',
                        'status' => $firstStatusKey,
                        'subtasks_done' => 0,
                        'subtasks_total' => 0,
                        'created_by' => $user->id,
                    ]);

                    TaskEvent::create([
                        'task_id' => $task->id,
                        'actor_id' => $user->id,
                        'kind' => 'created',
                        'at' => $now,
                    ]);
                }
            }

            $this->logEvent($project->id, $user->id, 'created', null, null, $template?->name);
            $this->audit->log(
                $user->id,
                'project_created',
                ['type' => 'project', 'id' => $project->id, 'label' => $project->name],
                $template ? [['field' => 'template', 'to' => $template->name]] : null
            );

            $project->load(['members:id', 'statuses', 'phases']);
            $this->notifications->notify($memberIds, 'project_added', [
                'actor_id' => $user->id,
                'project_id' => $project->id,
                'data' => $this->notifications->aboutProject($project),
            ]);

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(User $user, int $projectId, array $patch): Project
    {
        $project = $this->manageable($user, $projectId);

        if (array_key_exists('pm_id', $patch) && (int) $patch['pm_id'] !== (int) $project->pm_id && ! $this->access->canChangePm($user)) {
            throw ApiException::forbidden('api.projects.pm_change_forbidden');
        }

        if (array_key_exists('state', $patch) && ! in_array($patch['state'], Project::STATES, true)) {
            throw ApiException::validation('state', 'api.projects.state_invalid');
        }

        $this->checkFields($patch, $project);

        $note = isset($patch['note']) && trim((string) $patch['note']) !== '' ? trim((string) $patch['note']) : null;
        if ($note !== null && mb_strlen($note) > self::LIMITS['note']) {
            throw ApiException::validation('note', 'api.projects.note_max');
        }

        return DB::transaction(function () use ($user, $project, $patch, $note) {
            $project->refresh()->load(['members:id', 'statuses', 'phases']);
            $currentMembers = $project->memberIds();
            $pmId = array_key_exists('pm_id', $patch) ? (int) $patch['pm_id'] : (int) $project->pm_id;

            if (array_key_exists('member_ids', $patch)) {
                $nextMembers = $this->withPm($this->withPm(array_map('intval', $patch['member_ids']), $pmId), (int) $project->pm_id);
                $leaving = array_values(array_diff($currentMembers, $nextMembers));

                if ($leaving !== []) {
                    $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
                    foreach ($leaving as $leavingId) {
                        $openCount = Task::query()
                            ->where('project_id', $project->id)
                            ->where('assignee_id', $leavingId)
                            ->whereNotIn('status', $doneKeys)
                            ->count();
                        if ($openCount > 0) {
                            $name = User::query()->find($leavingId)?->name ?? '';
                            throw ApiException::badState('api.projects.member_has_open_tasks', ['name' => $name]);
                        }
                    }
                }

                $added = array_values(array_diff($nextMembers, $currentMembers));
                foreach ($added as $addedId) {
                    $this->logEvent($project->id, $user->id, 'member_added', null, (string) $addedId);
                    $this->notifications->notify([$addedId], 'project_added', [
                        'actor_id' => $user->id,
                        'project_id' => $project->id,
                        'data' => $this->notifications->aboutProject($project),
                    ]);
                }

                foreach ($leaving as $leftId) {
                    $this->logEvent($project->id, $user->id, 'member_removed', (string) $leftId, null);
                }

                $project->members()->sync($nextMembers);
                $currentMembers = $nextMembers;
            }

            if (array_key_exists('pm_id', $patch) && (int) $patch['pm_id'] !== (int) $project->pm_id) {
                $oldPmId = (int) $project->pm_id;
                $newPmId = (int) $patch['pm_id'];
                $this->logEvent($project->id, $user->id, 'pm', (string) $oldPmId, (string) $newPmId, $note);

                $pmNames = User::query()->whereIn('id', [$oldPmId, $newPmId])->pluck('name', 'id');
                $this->audit->log($user->id, 'project_pm_changed', [
                    'type' => 'project',
                    'id' => $project->id,
                    'label' => $project->name,
                ], [[
                    'field' => 'pm',
                    'from' => $pmNames[$oldPmId] ?? null,
                    'to' => $pmNames[$newPmId] ?? null,
                ]]);

                $allRecipients = $this->withPm($currentMembers, $newPmId);
                $this->notifications->notify($allRecipients, 'project_pm', [
                    'actor_id' => $user->id,
                    'project_id' => $project->id,
                    'data' => array_merge($this->notifications->aboutProject($project), [
                        'pm_id' => (string) $newPmId,
                        'note' => $note,
                    ]),
                ]);

                $project->members()->sync($allRecipients);
                $currentMembers = $allRecipients;
                $project->pm_id = $newPmId;
            }

            $newStart = $patch['start'] ?? $project->start;
            $newEnd = $patch['end'] ?? $project->end;
            if ($newStart !== $project->start || $newEnd !== $project->end) {
                $this->logEvent(
                    $project->id,
                    $user->id,
                    'dates',
                    "{$project->start} → {$project->end}",
                    "{$newStart} → {$newEnd}",
                    $note
                );
                $this->notifications->notify($currentMembers, 'project_plan', [
                    'actor_id' => $user->id,
                    'project_id' => $project->id,
                    'data' => array_merge($this->notifications->aboutProject($project), [
                        'due' => $newEnd,
                        'note' => $note,
                    ]),
                ]);
                $project->start = $newStart;
                $project->end = $newEnd;
            }

            if (array_key_exists('state', $patch) && $patch['state'] !== $project->state) {
                $this->logEvent($project->id, $user->id, 'state', $project->state, $patch['state'], $note);
                $project->state = $patch['state'];
            }

            $details = [];
            if (array_key_exists('name', $patch) && trim((string) $patch['name']) !== $project->name) {
                $project->name = trim((string) $patch['name']);
                $details[] = 'name';
            }
            if (array_key_exists('name_en', $patch)) {
                $nameEn = trim((string) ($patch['name_en'] ?? '')) ?: null;
                if ($nameEn !== $project->name_en) {
                    $project->name_en = $nameEn;
                    $details[] = 'name_en';
                }
            }
            if (array_key_exists('description', $patch) && trim((string) $patch['description']) !== $project->description) {
                $project->description = trim((string) $patch['description']);
                $details[] = 'description';
            }
            if (array_key_exists('priority', $patch) && $patch['priority'] !== $project->priority) {
                $project->priority = $patch['priority'];
                $details[] = 'priority';
            }
            if (array_key_exists('uses_sprints', $patch) && (bool) $patch['uses_sprints'] !== (bool) $project->uses_sprints) {
                $project->uses_sprints = (bool) $patch['uses_sprints'];
                $details[] = 'uses_sprints';
            }

            if ($details !== []) {
                $this->logEvent($project->id, $user->id, 'details', null, implode(',', $details), $note);
            }

            $project->save();

            return $project->fresh(['members:id', 'statuses', 'phases']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public function saveStatuses(User $user, int $projectId, array $statuses): Project
    {
        $project = $this->manageable($user, $projectId);
        if (count($statuses) === 0 || count($statuses) > self::LIMITS['statuses']) {
            throw ApiException::validation('statuses', 'api.projects.statuses_max', ['max' => self::LIMITS['statuses']]);
        }

        $current = $project->statuses->keyBy('key');
        foreach ($project->statuses->where('is_base', true) as $base) {
            $kept = collect($statuses)->first(fn ($s) => ($s['key'] ?? null) === $base->key);
            if (! $kept || ($kept['tone'] ?? null) !== $base->tone) {
                throw ApiException::validation('statuses', 'api.projects.statuses_base_locked');
            }
        }

        $next = [];
        $n = 0;
        $usedKeys = $current->keys()->all();

        foreach ($statuses as $s) {
            $key = isset($s['key']) && $s['key'] !== '' ? (string) $s['key'] : null;
            if ($key !== null && in_array($key, ProjectStatus::BASE_KEYS, true) && $current->has($key)) {
                $base = $current->get($key);
                $next[] = [
                    'key' => $base->key,
                    'name' => $base->name,
                    'name_en' => $base->name_en,
                    'tone' => $base->tone,
                    'is_base' => true,
                ];

                continue;
            }

            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                throw ApiException::validation('statuses', 'api.projects.statuses_name_required');
            }
            if (mb_strlen($name) > self::LIMITS['statusName']) {
                throw ApiException::validation('statuses', 'api.projects.statuses_name_max');
            }
            $tone = (string) ($s['tone'] ?? '');
            if (! in_array($tone, ProjectStatus::TONES, true)) {
                throw ApiException::validation('statuses', 'api.projects.statuses_tone_invalid');
            }
            if ($key !== null && ! $current->has($key)) {
                throw ApiException::validation('statuses', 'api.projects.statuses_unknown');
            }

            if ($key === null) {
                do {
                    $n++;
                    $key = 'custom_'.base_convert((string) now()->getTimestampMs(), 10, 36)."_{$n}";
                } while (in_array($key, $usedKeys, true));
                $usedKeys[] = $key;
            }

            $nameEn = trim((string) ($s['name_en'] ?? ''));
            $next[] = [
                'key' => $key,
                'name' => $name,
                'name_en' => $nameEn !== '' ? $nameEn : $name,
                'tone' => $tone,
                'is_base' => false,
            ];
        }

        $names = array_column($next, 'name');
        if (count(array_unique($names)) !== count($names)) {
            throw ApiException::validation('statuses', 'api.projects.statuses_duplicate');
        }

        if (in_array($next[0]['tone'], ['blocked', 'done'], true)) {
            throw ApiException::validation('statuses', 'api.projects.statuses_first_invalid');
        }

        $nextKeys = array_column($next, 'key');
        foreach ($project->statuses as $old) {
            if (in_array($old->key, $nextKeys, true)) {
                continue;
            }
            $usedCount = Task::query()
                ->where('project_id', $project->id)
                ->where('status', $old->key)
                ->count();
            if ($usedCount > 0) {
                $label = app()->getLocale() === 'en' ? $old->name_en : $old->name;
                throw ApiException::badState('api.projects.statuses_in_use', ['name' => $label, 'count' => $usedCount]);
            }
        }

        return DB::transaction(function () use ($user, $project, $next) {
            $before = $project->statuses->pluck('name')->implode('، ');

            $project->statuses()->delete();
            foreach ($next as $pos => $st) {
                ProjectStatus::create([
                    'project_id' => $project->id,
                    'key' => $st['key'],
                    'name' => $st['name'],
                    'name_en' => $st['name_en'],
                    'tone' => $st['tone'],
                    'position' => $pos,
                    'is_base' => $st['is_base'],
                ]);
            }

            $after = implode('، ', array_column($next, 'name'));
            $this->logEvent($project->id, $user->id, 'statuses', $before, $after);

            return $project->fresh(['members:id', 'statuses', 'phases']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $phases
     */
    public function savePhases(User $user, int $projectId, array $phases): Project
    {
        $project = $this->manageable($user, $projectId);
        if (count($phases) > self::LIMITS['phases']) {
            throw ApiException::validation('phases', 'api.projects.phases_max', ['max' => self::LIMITS['phases']]);
        }

        $existing = $project->phases->keyBy('id');
        $clean = [];
        foreach ($phases as $ph) {
            $name = trim((string) ($ph['name'] ?? ''));
            if ($name === '') {
                throw ApiException::validation('phases', 'api.projects.phases_name_required');
            }
            if (mb_strlen($name) > self::LIMITS['phaseName']) {
                throw ApiException::validation('phases', 'api.projects.phases_name_max');
            }
            $id = isset($ph['id']) ? (int) $ph['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ApiException::validation('phases', 'api.projects.phases_unknown');
            }
            $clean[] = ['id' => $id, 'name' => $name];
        }

        $names = array_column($clean, 'name');
        if (count(array_unique($names)) !== count($names)) {
            throw ApiException::validation('phases', 'api.projects.phases_duplicate');
        }

        $keptIds = array_values(array_filter(array_column($clean, 'id'), fn ($id) => $id !== null));
        foreach ($project->phases as $old) {
            if (in_array((int) $old->id, $keptIds, true)) {
                continue;
            }
            $usedCount = Task::query()
                ->where('project_id', $project->id)
                ->where('phase_id', $old->id)
                ->count();
            if ($usedCount > 0) {
                throw ApiException::badState('api.projects.phases_in_use', ['name' => $old->name, 'count' => $usedCount]);
            }
        }

        return DB::transaction(function () use ($user, $project, $clean, $keptIds) {
            $before = $project->phases->pluck('name')->implode('، ');

            $toDelete = $project->phases->filter(fn ($old) => ! in_array((int) $old->id, $keptIds, true))->pluck('id')->all();
            if ($toDelete !== []) {
                ProjectPhase::query()->whereIn('id', $toDelete)->delete();
            }

            foreach ($clean as $pos => $item) {
                if ($item['id'] !== null) {
                    ProjectPhase::query()->where('id', $item['id'])->update([
                        'name' => $item['name'],
                        'position' => $pos,
                    ]);
                } else {
                    ProjectPhase::create([
                        'project_id' => $project->id,
                        'name' => $item['name'],
                        'position' => $pos,
                    ]);
                }
            }

            $after = implode('، ', array_column($clean, 'name'));
            $this->logEvent($project->id, $user->id, 'phases', $before !== '' ? $before : null, $after !== '' ? $after : null);

            return $project->fresh(['members:id', 'statuses', 'phases']);
        });
    }

    /**
     * @return Collection<int, Sprint>
     */
    public function sprints(User $user, int $projectId): Collection
    {
        $this->access->projectForOrFail($user, $projectId, ['members:id']);

        return Sprint::query()
            ->where('project_id', $projectId)
            ->orderBy('start')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createSprint(User $user, int $projectId, array $input): Sprint
    {
        $project = $this->manageable($user, $projectId);
        if (! $project->uses_sprints) {
            throw ApiException::badState('api.projects.no_sprints');
        }

        $validated = $this->checkSprint($input, $projectId);

        return DB::transaction(function () use ($user, $projectId, $validated) {
            $sprint = Sprint::create([
                'project_id' => $projectId,
                'name' => $validated['name'],
                'start' => $validated['start'],
                'end' => $validated['end'],
            ]);

            $this->logEvent($projectId, $user->id, 'sprint_added', null, "{$sprint->name}: {$sprint->start} → {$sprint->end}");

            return $sprint;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateSprint(User $user, int $sprintId, array $input): Sprint
    {
        $sprint = Sprint::query()->find($sprintId);
        if (! $sprint) {
            throw ApiException::notFound('api.projects.sprint_not_found');
        }

        $this->manageable($user, $sprint->project_id);
        if ($sprint->closed_at !== null) {
            throw ApiException::badState('api.projects.sprint_ended');
        }

        $validated = $this->checkSprint($input, $sprint->project_id, $sprint->id);
        $before = "{$sprint->name}: {$sprint->start} → {$sprint->end}";

        return DB::transaction(function () use ($user, $sprint, $validated, $before) {
            $sprint->update($validated);
            $this->logEvent($sprint->project_id, $user->id, 'sprint_changed', $before, "{$sprint->name}: {$sprint->start} → {$sprint->end}");

            return $sprint->fresh();
        });
    }

    public function deleteSprint(User $user, int $sprintId): void
    {
        $sprint = Sprint::query()->find($sprintId);
        if (! $sprint) {
            throw ApiException::notFound('api.projects.sprint_not_found');
        }

        $this->manageable($user, $sprint->project_id);
        if ($sprint->closed_at !== null) {
            throw ApiException::badState('api.projects.sprint_ended');
        }

        $usedCount = Task::query()->where('sprint_id', $sprint->id)->count();
        if ($usedCount > 0) {
            throw ApiException::badState('api.projects.sprint_has_tasks', ['count' => $usedCount]);
        }

        DB::transaction(function () use ($user, $sprint) {
            $label = "{$sprint->name}: {$sprint->start} → {$sprint->end}";
            $projectId = $sprint->project_id;
            $sprint->delete();
            $this->logEvent($projectId, $user->id, 'sprint_removed', $label, null);
        });
    }

    public function closeSprint(User $user, int $sprintId, ?int $moveTo): Sprint
    {
        $sprint = Sprint::query()->find($sprintId);
        if (! $sprint) {
            throw ApiException::notFound('api.projects.sprint_not_found');
        }

        $project = $this->manageable($user, $sprint->project_id);
        if ($sprint->closed_at !== null) {
            throw ApiException::badState('api.projects.sprint_ended');
        }

        $today = BaghdadTime::today();
        if ($sprint->start > $today) {
            throw ApiException::badState('api.projects.sprint_not_started');
        }

        $target = null;
        if ($moveTo !== null) {
            $target = Sprint::query()->find($moveTo);
            if (! $target || (int) $target->project_id !== (int) $sprint->project_id || (int) $target->id === (int) $sprint->id || $target->closed_at !== null) {
                throw ApiException::validation('move_to', 'api.projects.sprint_move_to_invalid');
            }
        }

        return DB::transaction(function () use ($user, $project, $sprint, $target) {
            $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
            $mine = Task::query()->where('sprint_id', $sprint->id)->lockForUpdate()->get();
            $left = $mine->filter(fn (Task $t) => ! in_array($t->status, $doneKeys, true));
            $now = now('UTC');

            foreach ($left as $task) {
                $task->sprint_id = $target?->id;
                $task->updated_at = $now;
                $task->save();

                TaskEvent::create([
                    'task_id' => $task->id,
                    'actor_id' => $user->id,
                    'kind' => 'sprint',
                    'from' => $sprint->name,
                    'to' => $target?->name,
                    'at' => $now,
                ]);
            }

            $plannedCount = $mine->count();
            $movedCount = $left->count();
            $doneCount = $plannedCount - $movedCount;

            $result = [
                'planned' => $plannedCount,
                'done' => $doneCount,
                'moved' => $movedCount,
            ];
            if ($target !== null) {
                $result['moved_to'] = (int) $target->id;
            }

            $sprint->closed_at = $now;
            $sprint->result = $result;
            $sprint->save();

            $this->logEvent(
                $project->id,
                $user->id,
                'sprint_closed',
                $sprint->name,
                $target?->name,
                "{$doneCount}/{$plannedCount}"
            );

            $this->audit->log($user->id, 'sprint_closed', [
                'type' => 'sprint',
                'id' => $sprint->id,
                'label' => $sprint->name,
            ], [
                ['field' => 'moved_to', 'to' => $target?->name ?? 'backlog'],
                ['field' => 'done', 'to' => "{$doneCount}/{$plannedCount}"],
            ]);

            return $sprint->fresh();
        });
    }

    /**
     * @return Collection<int, ProjectTemplate>
     */
    public function templates(User $user): Collection
    {
        if (! $this->access->canUseTemplates($user)) {
            throw ApiException::forbidden('api.projects.templates_forbidden');
        }

        return ProjectTemplate::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveTemplate(User $user, int $projectId, array $input): ProjectTemplate
    {
        $project = $this->manageable($user, $projectId);
        if (! $this->access->canUseTemplates($user)) {
            throw ApiException::forbidden('api.projects.templates_forbidden');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ApiException::validation('name', 'api.projects.template_name_required');
        }
        if (mb_strlen($name) > self::LIMITS['templateName']) {
            throw ApiException::validation('name', 'api.projects.template_name_max');
        }
        if (ProjectTemplate::query()->where('name', $name)->exists()) {
            throw ApiException::validation('name', 'api.projects.template_name_taken');
        }

        $tasks = Task::query()->where('project_id', $project->id)->orderBy('id')->get();
        if ($tasks->count() > self::LIMITS['templateTasks']) {
            throw ApiException::validation('name', 'api.projects.template_tasks_max', ['max' => self::LIMITS['templateTasks']]);
        }

        $phaseNames = $project->phases->pluck('name', 'id');

        $tplTasks = $tasks->map(function (Task $t) use ($phaseNames) {
            $row = [
                'title' => $t->title,
                'description' => $t->description ?? '',
                'priority' => $t->priority,
            ];
            if ($t->phase_id !== null && isset($phaseNames[$t->phase_id])) {
                $row['phase'] = $phaseNames[$t->phase_id];
            }

            return $row;
        })->values()->all();

        $statuses = $project->statuses->map(fn (ProjectStatus $s) => [
            'key' => $s->key,
            'name' => $s->name,
            'name_en' => $s->name_en,
            'tone' => $s->tone,
        ])->values()->all();

        return ProjectTemplate::create([
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'statuses' => $statuses,
            'phases' => $project->phases->pluck('name')->values()->all(),
            'uses_sprints' => (bool) $project->uses_sprints,
            'tasks' => $tplTasks,
            'from_project' => $project->name,
            'created_by' => $user->id,
            'created_at' => now('UTC'),
        ]);
    }

    public function deleteTemplate(User $user, int $templateId): void
    {
        $template = ProjectTemplate::query()->find($templateId);
        if (! $template || ! $this->access->canUseTemplates($user)) {
            throw ApiException::notFound('api.projects.template_not_found_404');
        }
        if (! $this->access->canRemoveTemplate($user, $template)) {
            throw ApiException::forbidden('api.projects.template_delete_forbidden');
        }

        DB::transaction(function () use ($user, $template) {
            $id = $template->id;
            $name = $template->name;
            $template->delete();
            $this->audit->log($user->id, 'template_deleted', [
                'type' => 'template',
                'id' => $id,
                'label' => $name,
            ]);
        });
    }

    /**
     * @return Collection<int, ProjectEvent>
     */
    public function history(User $user, int $projectId): Collection
    {
        $this->access->projectForOrFail($user, $projectId, ['members:id']);

        return ProjectEvent::query()
            ->where('project_id', $projectId)
            ->orderBy('at')
            ->orderBy('id')
            ->get();
    }

    private function manageable(User $user, int $projectId): Project
    {
        $project = $this->access->projectForOrFail($user, $projectId);
        if (! $this->access->canManageProjectSetup($user, $project)) {
            throw ApiException::forbidden('api.projects.manage_forbidden');
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function checkFields(array $fields, ?Project $current = null): void
    {
        if (array_key_exists('name', $fields)) {
            $name = trim((string) $fields['name']);
            if ($name === '') {
                throw ApiException::validation('name', 'api.projects.name_required');
            }
            if (mb_strlen($name) > self::LIMITS['name']) {
                throw ApiException::validation('name', 'api.projects.name_max');
            }
        }

        if (array_key_exists('description', $fields) && mb_strlen((string) $fields['description']) > self::LIMITS['description']) {
            throw ApiException::validation('description', 'api.projects.description_max');
        }

        $start = $fields['start'] ?? $current?->start;
        $end = $fields['end'] ?? $current?->end;

        if (array_key_exists('start', $fields) && ! $this->isDay($fields['start'])) {
            throw ApiException::validation('start', 'api.projects.start_required');
        }
        if (array_key_exists('end', $fields) && ! $this->isDay($fields['end'])) {
            throw ApiException::validation('end', 'api.projects.end_required');
        }
        if ($start !== null && $end !== null && $end < $start) {
            throw ApiException::validation('end', 'api.projects.end_before_start');
        }

        if (array_key_exists('pm_id', $fields)) {
            $pm = User::query()->where('id', (int) $fields['pm_id'])->where('active', true)->first();
            if (! $pm || $pm->role !== 'pm') {
                throw ApiException::validation('pm_id', 'api.projects.pm_invalid');
            }
        }

        if (array_key_exists('member_ids', $fields)) {
            $ids = array_values(array_unique(array_map('intval', (array) $fields['member_ids'])));
            if ($ids !== []) {
                $validCount = User::query()
                    ->whereIn('id', $ids)
                    ->where('active', true)
                    ->where('role', '!=', 'admin')
                    ->count();
                if ($validCount !== count($ids)) {
                    throw ApiException::validation('member_ids', 'api.projects.members_invalid');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, start: string, end: string}
     */
    private function checkSprint(array $input, int $projectId, ?int $selfId = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ApiException::validation('name', 'api.projects.sprint_name_required');
        }
        if (mb_strlen($name) > self::LIMITS['sprintName']) {
            throw ApiException::validation('name', 'api.projects.sprint_name_max');
        }

        $start = $input['start'] ?? null;
        $end = $input['end'] ?? null;
        if (! $this->isDay($start)) {
            throw ApiException::validation('start', 'api.projects.sprint_start_required');
        }
        if (! $this->isDay($end)) {
            throw ApiException::validation('end', 'api.projects.sprint_end_required');
        }
        if ($end < $start) {
            throw ApiException::validation('end', 'api.projects.sprint_end_before_start');
        }

        $clash = Sprint::query()
            ->where('project_id', $projectId)
            ->when($selfId !== null, fn ($q) => $q->where('id', '!=', $selfId))
            ->where('start', '<=', $end)
            ->where('end', '>=', $start)
            ->first();

        if ($clash) {
            throw ApiException::validation('start', 'api.projects.sprint_overlap', ['name' => $clash->name]);
        }

        return ['name' => $name, 'start' => $start, 'end' => $end];
    }

    /**
     * @param  int[]  $ids
     * @return int[]
     */
    private function withPm(array $ids, int $pmId): array
    {
        return array_values(array_unique(array_merge([$pmId], $ids)));
    }

    private function logEvent(int $projectId, int $actorId, string $kind, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        ProjectEvent::create([
            'project_id' => $projectId,
            'actor_id' => $actorId,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'at' => now('UTC'),
        ]);
    }

    private function isDay(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
