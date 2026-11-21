<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Bug;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\BaghdadTime;
use Illuminate\Database\QueryException;

class NotificationService
{
    private readonly AdvancedSettingsService $advanced;

    public function __construct(?AdvancedSettingsService $advanced = null)
    {
        $this->advanced = $advanced ?? app(AdvancedSettingsService::class);
    }

    public function wants(int $userId, string $kind): bool
    {
        if (in_array($kind, Notification::REQUIRED_KINDS, true)) {
            return true;
        }

        $pref = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->first();

        return $pref ? (bool) $pref->enabled : true;
    }

    /**
     * @param  array<int, int|null|undefined>  $recipientIds
     * @param  array{actor_id?: int|null, project_id?: int|null, task_id?: int|null, announcement_id?: int|null, bug_id?: int|null, chat_key?: string|null, data: array<string, mixed>, dedup_key?: string|null}  $about
     */
    public function notify(array $recipientIds, string $kind, array $about): int
    {
        $actorId = $about['actor_id'] ?? null;
        $uniqueIds = [];
        foreach ($recipientIds as $id) {
            if ($id === null) {
                continue;
            }
            $uid = (int) $id;
            if ($actorId !== null && $uid === (int) $actorId) {
                continue;
            }
            $uniqueIds[$uid] = $uid;
        }

        if ($uniqueIds === []) {
            return 0;
        }

        // Batch-fetch disabled preferences & role matrix when kind is optional.
        $disabled = [];
        $rolesByUser = [];
        if (! in_array($kind, Notification::REQUIRED_KINDS, true)) {
            $rolesByUser = User::query()
                ->whereIn('id', array_values($uniqueIds))
                ->pluck('role', 'id')
                ->all();

            $disabled = NotificationPreference::query()
                ->whereIn('user_id', array_values($uniqueIds))
                ->where('kind', $kind)
                ->where('enabled', false)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $created = 0;
        $now = now('UTC');
        foreach ($uniqueIds as $userId) {
            $role = $rolesByUser[$userId] ?? null;
            if ($role !== null && ! $this->advanced->jobGets((string) $role, $kind)) {
                continue;
            }
            if (in_array($userId, $disabled, true)) {
                continue;
            }

            $dedupKey = $about['dedup_key'] ?? null;
            if ($dedupKey !== null && count($uniqueIds) > 1) {
                $dedupKey = "{$dedupKey}:u{$userId}";
            }

            try {
                Notification::create([
                    'user_id' => $userId,
                    'kind' => $kind,
                    'actor_id' => $actorId,
                    'project_id' => $about['project_id'] ?? null,
                    'task_id' => $about['task_id'] ?? null,
                    'announcement_id' => $about['announcement_id'] ?? null,
                    'bug_id' => $about['bug_id'] ?? null,
                    'chat_key' => $about['chat_key'] ?? null,
                    'data' => $about['data'],
                    'dedup_key' => $dedupKey,
                    'at' => $now,
                    'read' => false,
                ]);
                $created++;
            } catch (QueryException $e) {
                // Idempotent deduplication on unique dedup_key.
                if ($dedupKey === null) {
                    throw $e;
                }
            }
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    public function aboutTask(Task $task, Project $project): array
    {
        $data = [
            'task_code' => $task->code,
            'task_title' => $task->title,
            'project_name' => $project->name,
        ];
        if ($project->name_en !== null && $project->name_en !== '') {
            $data['project_name_en'] = $project->name_en;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function aboutProject(Project $project): array
    {
        $data = ['project_name' => $project->name];
        if ($project->name_en !== null && $project->name_en !== '') {
            $data['project_name_en'] = $project->name_en;
        }

        return $data;
    }

    /**
     * Run daily Baghdad-time reminders for due/overdue tasks and late bugs.
     *
     * @return array{tasks_due_soon: int, tasks_overdue: int, bugs_late: int}
     */
    public function sendDailyReminders(): array
    {
        $today = BaghdadTime::today();
        $dueSoonDays = AppSetting::current()->due_soon_days;
        $counts = ['tasks_due_soon' => 0, 'tasks_overdue' => 0, 'bugs_late' => 0];

        $tasks = Task::query()
            ->whereNotNull('assignee_id')
            ->whereNotNull('due')
            ->with(['project.statuses', 'assignee:id,active'])
            ->get();

        foreach ($tasks as $task) {
            if (! $task->project || ! $task->assignee?->active) {
                continue;
            }
            $doneKeys = $task->project->statuses->where('tone', 'done')->pluck('key')->all();
            if (in_array($task->status, $doneKeys, true)) {
                continue;
            }

            $left = BaghdadTime::daysBetween($today, $task->due);
            $kind = $left < 0 ? 'task_overdue' : ($left <= $dueSoonDays ? 'task_due_soon' : null);
            if ($kind === null) {
                continue;
            }

            $dedup = "{$kind}:{$task->assignee_id}:task:{$task->id}:{$task->due}";
            if (Notification::query()->where('dedup_key', $dedup)->exists()) {
                continue;
            }

            $created = $this->notify([$task->assignee_id], $kind, [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'dedup_key' => $dedup,
                'data' => array_merge($this->aboutTask($task, $task->project), ['due' => $task->due]),
            ]);
            if ($created > 0) {
                $counts[$kind === 'task_overdue' ? 'tasks_overdue' : 'tasks_due_soon'] += $created;
            }
        }

        $bugs = Bug::query()
            ->where('status', '!=', 'closed')
            ->whereNotNull('due')
            ->where('due', '<', $today)
            ->with(['project.pm:id,active', 'project'])
            ->get();

        foreach ($bugs as $bug) {
            if (! $bug->project) {
                continue;
            }

            $recipients = array_filter([
                $bug->assignee_id,
                $bug->project->pm?->active ? $bug->project->pm_id : null,
            ]);

            foreach (array_unique($recipients) as $uid) {
                $dedup = "bug_late:{$uid}:bug:{$bug->id}:{$bug->due}";
                if (Notification::query()->where('dedup_key', $dedup)->exists()) {
                    continue;
                }
                $created = $this->notify([$uid], 'bug_late', [
                    'project_id' => $bug->project_id,
                    'bug_id' => $bug->id,
                    'dedup_key' => $dedup,
                    'data' => [
                        'bug_code' => $bug->code,
                        'bug_title' => $bug->title,
                        'due' => $bug->due,
                    ],
                ]);
                $counts['bugs_late'] += $created;
            }
        }

        return $counts;
    }
}
