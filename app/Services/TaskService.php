<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskComment;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public const LIMITS = [
        'title' => 160,
        'description' => 5000,
        'comment' => 2000,
        'reason' => 300,
        'dependencies' => 10,
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly CodeGenerator $codes,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return Collection<int, Task>
     */
    public function listForProject(User $user, int $projectId): Collection
    {
        $this->access->projectForOrFail($user, $projectId, ['members:id']);

        return Task::query()
            ->where('project_id', $projectId)
            ->with(['dependencies:id', 'currentApproval'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Task>
     */
    public function myTasks(User $user): Collection
    {
        $visibleProjectIds = Project::query()
            ->with('members:id')
            ->get()
            ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
            ->pluck('id')
            ->all();

        if ($visibleProjectIds === []) {
            return collect();
        }

        return Task::query()
            ->where('assignee_id', $user->id)
            ->whereIn('project_id', $visibleProjectIds)
            ->with(['dependencies:id', 'currentApproval'])
            ->orderBy('id')
            ->get();
    }

    public function show(User $user, int $taskId): Task
    {
        return $this->taskForOrFail($user, $taskId)['task'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, int $projectId, array $input): Task
    {
        $project = $this->access->projectForOrFail($user, $projectId);
        if (! $this->access->canManageTasks($user, $project)) {
            throw ApiException::forbidden('api.tasks.create_forbidden');
        }

        $fields = $input;
        $this->checkFields($project, $fields, true);

        $firstStatus = $project->statuses->first();
        $statusKey = isset($input['status']) && $input['status'] !== '' ? (string) $input['status'] : $firstStatus->key;
        $target = $project->statuses->firstWhere('key', $statusKey);
        if (! $target) {
            throw ApiException::validation('status', 'api.tasks.status_unknown');
        }
        if (in_array($target->tone, ['blocked', 'done'], true)) {
            throw ApiException::validation('status', 'api.tasks.status_start_invalid');
        }

        return DB::transaction(function () use ($user, $project, $fields, $statusKey) {
            $task = Task::create([
                'code' => $this->codes->nextTaskCode(),
                'project_id' => $project->id,
                'title' => trim((string) $fields['title']),
                'description' => isset($fields['description']) ? trim((string) $fields['description']) : '',
                'assignee_id' => $fields['assignee_id'] ?? null,
                'priority' => $fields['priority'],
                'due' => ! empty($fields['due']) ? $fields['due'] : null,
                'start' => ! empty($fields['start']) ? $fields['start'] : null,
                'sprint_id' => $project->uses_sprints ? ($fields['sprint_id'] ?? null) : null,
                'phase_id' => $fields['phase_id'] ?? null,
                'status' => $statusKey,
                'subtasks_done' => 0,
                'subtasks_total' => 0,
                'created_by' => $user->id,
            ]);

            $this->logEvent($task->id, $user->id, 'created');

            if ($task->assignee_id !== null) {
                $this->notifications->notify([$task->assignee_id], 'task_assigned', [
                    'actor_id' => $user->id,
                    'project_id' => $project->id,
                    'task_id' => $task->id,
                    'data' => $this->notifications->aboutTask($task, $project),
                ]);
            }

            return $task->fresh(['dependencies:id', 'currentApproval']);
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(User $user, int $taskId, array $patch): Task
    {
        ['task' => $task, 'project' => $project] = $this->taskForOrFail($user, $taskId);
        if (! $this->access->canManageTasks($user, $project)) {
            throw ApiException::forbidden('api.tasks.edit_forbidden');
        }

        if (array_key_exists('assignee_id', $patch)) {
            $nextAssignee = ($patch['assignee_id'] !== null && $patch['assignee_id'] !== '') ? (int) $patch['assignee_id'] : null;
            $prevAssignee = $task->assignee_id !== null ? (int) $task->assignee_id : null;
            if ($nextAssignee !== $prevAssignee) {
                $this->advanced->requireAction($user, 'reassign_task');
            }
        }
        if (array_key_exists('due', $patch)) {
            $nextDue = ($patch['due'] !== null && $patch['due'] !== '') ? (string) $patch['due'] : null;
            $prevDue = $task->due !== null && $task->due !== '' ? (string) $task->due : null;
            if ($nextDue !== $prevDue) {
                $this->advanced->requireAction($user, 'change_due');
            }
        }

        $checkInput = $patch;
        if (! array_key_exists('start', $checkInput)) {
            $checkInput['start'] = $task->start;
        }
        if (! array_key_exists('due', $checkInput)) {
            $checkInput['due'] = $task->due;
        }
        $this->checkFields($project, $checkInput, false);

        $dueNote = isset($patch['due_note']) && trim((string) $patch['due_note']) !== ''
            ? trim((string) $patch['due_note'])
            : null;
        if ($dueNote !== null && mb_strlen($dueNote) > self::LIMITS['reason']) {
            throw ApiException::validation('due_note', 'api.tasks.note_max');
        }

        return DB::transaction(function () use ($user, $task, $project, $patch, $dueNote) {
            $map = [
                'title' => 'title',
                'description' => 'description',
                'assignee_id' => 'assignee',
                'priority' => 'priority',
                'due' => 'due',
                'phase_id' => 'phase',
            ];

            foreach ($map as $field => $kind) {
                if (! array_key_exists($field, $patch)) {
                    continue;
                }

                $before = $task->{$field};
                $raw = $field === 'title' ? trim((string) $patch[$field]) : $patch[$field];
                $after = ($raw === null || $raw === '') ? null : $raw;
                if ($field === 'assignee_id' || $field === 'phase_id') {
                    $after = $after !== null ? (int) $after : null;
                    $before = $before !== null ? (int) $before : null;
                }

                if ($before === $after) {
                    continue;
                }

                $this->logEvent(
                    $task->id,
                    $user->id,
                    $kind,
                    $kind === 'description' || $before === null ? null : (string) $before,
                    $kind === 'description' || $after === null ? null : (string) $after,
                    $kind === 'due' ? $dueNote : null
                );

                $task->{$field} = $after;

                if ($kind === 'assignee' && $after !== null) {
                    $this->notifications->notify([(int) $after], 'task_assigned', [
                        'actor_id' => $user->id,
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                        'data' => $this->notifications->aboutTask($task, $project),
                    ]);
                }

                if ($kind === 'due' && $task->assignee_id !== null) {
                    $this->notifications->notify([$task->assignee_id], 'task_due_changed', [
                        'actor_id' => $user->id,
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                        'data' => array_merge($this->notifications->aboutTask($task, $project), [
                            'due' => $after !== null ? (string) $after : null,
                            'note' => $dueNote,
                        ]),
                    ]);
                }
            }

            if (array_key_exists('start', $patch)) {
                $task->start = ! empty($patch['start']) ? $patch['start'] : null;
            }

            if (array_key_exists('sprint_id', $patch) && $project->uses_sprints) {
                $nextSprintId = $patch['sprint_id'] !== null ? (int) $patch['sprint_id'] : null;
                $prevSprintId = $task->sprint_id !== null ? (int) $task->sprint_id : null;
                if ($nextSprintId !== $prevSprintId) {
                    if ($prevSprintId !== null) {
                        $oldSprint = Sprint::query()->find($prevSprintId);
                        if ($oldSprint && $oldSprint->closed_at !== null) {
                            throw ApiException::validation('sprint_id', 'api.tasks.task_in_closed_sprint');
                        }
                    }

                    $oldName = $prevSprintId !== null ? Sprint::query()->find($prevSprintId)?->name : null;
                    $newName = $nextSprintId !== null ? Sprint::query()->find($nextSprintId)?->name : null;
                    $this->logEvent($task->id, $user->id, 'sprint', $oldName, $newName);
                    $task->sprint_id = $nextSprintId;
                }
            }

            $task->touch();
            $task->save();

            return $task->fresh(['dependencies:id', 'currentApproval']);
        });
    }

    /**
     * @return array{task: Task, outcome: string}
     */
    public function move(User $user, int $taskId, string $toStatus, ?string $blockedReason = null): array
    {
        ['task' => $task, 'project' => $project] = $this->taskForOrFail($user, $taskId);

        if (! $this->access->canMoveTask($user, $project, $task)) {
            throw ApiException::forbidden('api.tasks.move_forbidden');
        }

        $reason = $blockedReason !== null ? trim($blockedReason) : null;
        if ($reason === '') {
            $reason = null;
        }
        if ($reason !== null && mb_strlen($reason) > self::LIMITS['reason']) {
            throw ApiException::validation('blocked_reason', 'api.tasks.reason_max');
        }

        $target = $project->statuses->firstWhere('key', $toStatus);
        if (! $target) {
            throw ApiException::validation('status', 'api.tasks.status_unknown');
        }

        if ($task->status === $toStatus) {
            return ['task' => $task, 'outcome' => 'moved'];
        }

        if ($target->tone === 'blocked' && $reason === null) {
            throw ApiException::validation('blocked_reason', 'api.tasks.blocked_reason_required');
        }

        $canApprove = $this->access->canApprove($user, $project);
        if ($target->tone === 'done' && ! $canApprove) {
            $current = $task->currentApproval;
            if ($current && $current->state === 'pending' && $current->to_status === $toStatus) {
                return ['task' => $task, 'outcome' => 'moved'];
            }
        }

        return DB::transaction(function () use ($user, $task, $project, $target, $toStatus, $reason, $canApprove) {
            $locked = Task::query()->with(['dependencies:id', 'currentApproval'])->lockForUpdate()->findOrFail($task->id);

            if ($target->tone === 'done') {
                $this->mustNotWait($project, $locked);
            }

            if ($target->tone === 'done' && ! $canApprove) {
                TaskApproval::query()
                    ->where('task_id', $locked->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                TaskApproval::create([
                    'task_id' => $locked->id,
                    'state' => 'pending',
                    'from_status' => $locked->status,
                    'to_status' => $toStatus,
                    'requested_by' => $user->id,
                    'requested_at' => now('UTC'),
                    'is_current' => true,
                ]);

                $this->logEvent($locked->id, $user->id, 'approval_requested', $locked->status, $toStatus);

                $approvers = $this->approverRecipientIds($project);
                $this->notifications->notify($approvers, 'approval_requested', [
                    'actor_id' => $user->id,
                    'project_id' => $project->id,
                    'task_id' => $locked->id,
                    'data' => $this->notifications->aboutTask($locked, $project),
                ]);

                $locked->touch();
                $locked->save();

                return [
                    'task' => $locked->fresh(['dependencies:id', 'currentApproval']),
                    'outcome' => 'approval_requested',
                ];
            }

            $from = $locked->status;
            $fromStatus = $project->statuses->firstWhere('key', $from);
            $leavingBlocked = $fromStatus?->tone === 'blocked';

            $locked->status = $toStatus;
            if ($target->tone === 'blocked') {
                $locked->blocked_reason = $reason;
                $this->logEvent($locked->id, $user->id, 'blocked', $from, $toStatus, $reason);
            } else {
                $locked->blocked_reason = null;
                $this->logEvent($locked->id, $user->id, $leavingBlocked ? 'unblocked' : 'status', $from, $toStatus);
            }

            $pending = $locked->currentApproval;
            $pendingRequesterId = null;

            if ($target->tone === 'done') {
                $now = now('UTC');
                if ($pending && $pending->state === 'pending') {
                    $pendingRequesterId = (int) $pending->requested_by;
                    $pending->update([
                        'state' => 'approved',
                        'to_status' => $toStatus,
                        'decided_by' => $user->id,
                        'decided_at' => $now,
                    ]);
                } else {
                    TaskApproval::query()
                        ->where('task_id', $locked->id)
                        ->where('is_current', true)
                        ->update(['is_current' => false]);

                    TaskApproval::create([
                        'task_id' => $locked->id,
                        'state' => 'approved',
                        'from_status' => $from,
                        'to_status' => $toStatus,
                        'requested_by' => $user->id,
                        'requested_at' => $now,
                        'decided_by' => $user->id,
                        'decided_at' => $now,
                        'is_current' => true,
                    ]);
                }

                $this->logEvent($locked->id, $user->id, 'approved', $from, $toStatus);
            } elseif ($pending && $pending->state === 'pending') {
                $pending->update([
                    'state' => 'cancelled',
                    'is_current' => false,
                ]);
            }

            $locked->touch();
            $locked->save();

            $this->notifications->notify([$locked->assignee_id, $project->pm_id], 'task_status', [
                'actor_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $locked->id,
                'data' => array_merge($this->notifications->aboutTask($locked, $project), [
                    'status' => $target->name,
                    'status_en' => $target->name_en,
                    'note' => $reason,
                ]),
            ]);

            if ($target->tone === 'done') {
                $this->freeWaiting($project, $locked, $user->id);
                if ($pendingRequesterId !== null && $pendingRequesterId !== (int) $user->id) {
                    $this->notifications->notify([$pendingRequesterId], 'approval_decided', [
                        'actor_id' => $user->id,
                        'project_id' => $project->id,
                        'task_id' => $locked->id,
                        'data' => array_merge($this->notifications->aboutTask($locked, $project), [
                            'decision' => 'approved',
                        ]),
                    ]);
                }
            }

            return [
                'task' => $locked->fresh(['dependencies:id', 'currentApproval']),
                'outcome' => 'moved',
            ];
        });
    }

    public function decideCompletion(User $user, int $taskId, bool $approve, ?string $note = null): Task
    {
        ['task' => $task, 'project' => $project] = $this->taskForOrFail($user, $taskId);
        if (! $this->access->canApprove($user, $project)) {
            throw ApiException::forbidden('api.tasks.approve_forbidden');
        }

        $cleanNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        if (! $approve && $cleanNote === null) {
            throw ApiException::validation('note', 'api.tasks.reject_note_required');
        }
        if ($cleanNote !== null && mb_strlen($cleanNote) > self::LIMITS['reason']) {
            throw ApiException::validation('note', 'api.tasks.note_max');
        }

        return DB::transaction(function () use ($user, $task, $project, $approve, $cleanNote) {
            $locked = Task::query()->with(['dependencies:id', 'currentApproval'])->lockForUpdate()->findOrFail($task->id);
            $approval = $locked->currentApproval;

            if (! $approval || $approval->state !== 'pending') {
                throw ApiException::badState('api.tasks.no_pending_approval');
            }

            $now = now('UTC');
            if ($approve) {
                $this->mustNotWait($project, $locked);
                $approval->update([
                    'state' => 'approved',
                    'decided_by' => $user->id,
                    'decided_at' => $now,
                    'note' => $cleanNote,
                ]);
                $this->logEvent($locked->id, $user->id, 'approved', $locked->status, $approval->to_status, $cleanNote);
                $locked->status = $approval->to_status;
                $locked->blocked_reason = null;
            } else {
                $approval->update([
                    'state' => 'rejected',
                    'decided_by' => $user->id,
                    'decided_at' => $now,
                    'note' => $cleanNote,
                ]);
                $this->logEvent($locked->id, $user->id, 'rejected', $approval->to_status, $approval->from_status, $cleanNote);
                $locked->status = $approval->from_status;
            }

            $locked->touch();
            $locked->save();

            if ($approve) {
                $this->freeWaiting($project, $locked, $user->id);
            }

            $this->notifications->notify([$approval->requested_by], 'approval_decided', [
                'actor_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $locked->id,
                'data' => array_merge($this->notifications->aboutTask($locked, $project), [
                    'decision' => $approve ? 'approved' : 'rejected',
                    'note' => $cleanNote,
                ]),
            ]);

            return $locked->fresh(['dependencies:id', 'currentApproval']);
        });
    }

    /**
     * @param  int[]  $dependsOn
     */
    public function setDependencies(User $user, int $taskId, array $dependsOn): Task
    {
        ['task' => $task, 'project' => $project] = $this->taskForOrFail($user, $taskId);
        if (! $this->access->canManageTasks($user, $project)) {
            throw ApiException::forbidden('api.tasks.dependencies_forbidden');
        }

        $next = array_values(array_unique(array_map('intval', $dependsOn)));
        if (count($next) > self::LIMITS['dependencies']) {
            throw ApiException::validation('depends_on', 'api.tasks.dependencies_max', ['max' => self::LIMITS['dependencies']]);
        }
        if (in_array($task->id, $next, true)) {
            throw ApiException::validation('depends_on', 'api.tasks.dependencies_self');
        }

        return DB::transaction(function () use ($user, $task, $project, $next) {
            $projectTasks = Task::query()
                ->where('project_id', $project->id)
                ->with('dependencies:id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($next as $depId) {
                if (! $projectTasks->has($depId)) {
                    throw ApiException::validation('depends_on', 'api.tasks.dependencies_same_project');
                }
            }

            if ($this->makesLoop($task->id, $next, $projectTasks)) {
                throw ApiException::validation('depends_on', 'api.tasks.dependencies_loop');
            }

            $locked = $projectTasks->get($task->id);
            $before = $locked->dependencies->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($next as $depId) {
                if (! in_array($depId, $before, true)) {
                    $this->logEvent($locked->id, $user->id, 'dependency_added', null, $projectTasks->get($depId)->code);
                }
            }
            foreach ($before as $depId) {
                if (! in_array($depId, $next, true)) {
                    $this->logEvent($locked->id, $user->id, 'dependency_removed', $projectTasks->get($depId)?->code, null);
                }
            }

            $locked->dependencies()->sync($next);
            $locked->touch();
            $locked->save();

            return $locked->fresh(['dependencies:id', 'currentApproval']);
        });
    }

    /**
     * @return Collection<int, TaskComment>
     */
    public function comments(User $user, int $taskId): Collection
    {
        $this->taskForOrFail($user, $taskId);

        return TaskComment::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get();
    }

    public function addComment(User $user, int $taskId, string $body): TaskComment
    {
        ['task' => $task, 'project' => $project] = $this->taskForOrFail($user, $taskId);
        if (! $this->access->canComment($user, $project)) {
            throw ApiException::forbidden('api.tasks.comment_forbidden');
        }

        $text = trim($body);
        if ($text === '') {
            throw ApiException::validation('body', 'api.tasks.comment_required');
        }
        if (mb_strlen($text) > self::LIMITS['comment']) {
            throw ApiException::validation('body', 'api.tasks.comment_max', ['max' => self::LIMITS['comment']]);
        }

        return DB::transaction(function () use ($user, $task, $project, $text) {
            $priorCommenters = TaskComment::query()
                ->where('task_id', $task->id)
                ->pluck('author_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $comment = TaskComment::create([
                'task_id' => $task->id,
                'author_id' => $user->id,
                'body' => $text,
                'created_at' => now('UTC'),
            ]);

            $this->logEvent($task->id, $user->id, 'comment');
            $task->touch();
            $task->save();

            $recipients = array_merge([$task->assignee_id, $project->pm_id], $priorCommenters);
            $this->notifications->notify($recipients, 'task_comment', [
                'actor_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task->id,
                'data' => $this->notifications->aboutTask($task, $project),
            ]);

            return $comment;
        });
    }

    /**
     * @return Collection<int, TaskEvent>
     */
    public function history(User $user, int $taskId): Collection
    {
        $this->taskForOrFail($user, $taskId);

        return TaskEvent::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{task: Task, project: Project}
     */
    public function taskForOrFail(User $user, int $taskId): array
    {
        $task = Task::query()->with(['dependencies:id', 'currentApproval', 'project.members:id', 'project.statuses', 'project.phases'])->find($taskId);
        if (! $task || ! $task->project || ! $this->access->canSeeProject($user, $task->project)) {
            throw ApiException::notFound('api.tasks.not_found');
        }

        return ['task' => $task, 'project' => $task->project];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function checkFields(Project $project, array $fields, bool $isCreate): void
    {
        if ($isCreate || array_key_exists('title', $fields)) {
            $title = trim((string) ($fields['title'] ?? ''));
            if ($title === '') {
                throw ApiException::validation('title', 'api.tasks.title_required');
            }
            if (mb_strlen($title) > self::LIMITS['title']) {
                throw ApiException::validation('title', 'api.tasks.title_max', ['max' => self::LIMITS['title']]);
            }
        }

        if (array_key_exists('description', $fields) && $fields['description'] !== null && mb_strlen((string) $fields['description']) > self::LIMITS['description']) {
            throw ApiException::validation('description', 'api.tasks.description_max');
        }

        if (array_key_exists('priority', $fields) && ! in_array($fields['priority'], Task::PRIORITIES, true)) {
            throw ApiException::validation('priority', 'api.tasks.priority_invalid');
        }

        if (array_key_exists('assignee_id', $fields) && $fields['assignee_id'] !== null) {
            $memberIds = $project->memberIds();
            if (! in_array((int) $fields['assignee_id'], $memberIds, true)) {
                throw ApiException::validation('assignee_id', 'api.tasks.assignee_not_member');
            }
        }

        $start = ! empty($fields['start']) ? (string) $fields['start'] : null;
        $due = ! empty($fields['due']) ? (string) $fields['due'] : null;
        if ($start !== null && $due !== null && $due < $start) {
            throw ApiException::validation('due', 'api.tasks.due_before_start');
        }

        if (array_key_exists('sprint_id', $fields) && $fields['sprint_id'] !== null) {
            $sprint = Sprint::query()
                ->where('id', (int) $fields['sprint_id'])
                ->where('project_id', $project->id)
                ->first();
            if (! $sprint) {
                throw ApiException::validation('sprint_id', 'api.tasks.sprint_not_in_project');
            }
            if ($sprint->closed_at !== null) {
                throw ApiException::validation('sprint_id', 'api.tasks.sprint_closed');
            }
        }

        if (array_key_exists('phase_id', $fields) && $fields['phase_id'] !== null) {
            if (! $project->phases->contains('id', (int) $fields['phase_id'])) {
                throw ApiException::validation('phase_id', 'api.tasks.phase_not_in_project');
            }
        }
    }

    private function mustNotWait(Project $project, Task $task): void
    {
        $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
        $depIds = $task->dependencies->pluck('id')->all();
        if ($depIds === []) {
            return;
        }

        $openDeps = Task::query()
            ->whereIn('id', $depIds)
            ->whereNotIn('status', $doneKeys)
            ->orderBy('id')
            ->pluck('code')
            ->all();

        if ($openDeps !== []) {
            $sep = app()->getLocale() === 'ar' ? '، ' : ', ';
            throw ApiException::badState('api.tasks.waiting_for_dependencies', ['codes' => implode($sep, $openDeps)]);
        }
    }

    private function freeWaiting(Project $project, Task $doneTask, int $actorId): void
    {
        $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
        $waiters = Task::query()
            ->whereHas('dependencies', fn ($q) => $q->where('depends_on_task_id', $doneTask->id))
            ->with('dependencies:id,status')
            ->get();

        foreach ($waiters as $waiter) {
            $hasOpen = $waiter->dependencies->contains(fn (Task $dep) => ! in_array($dep->status, $doneKeys, true));
            if ($hasOpen || $waiter->assignee_id === null) {
                continue;
            }

            $this->notifications->notify([$waiter->assignee_id], 'dependency_done', [
                'actor_id' => $actorId,
                'project_id' => $project->id,
                'task_id' => $waiter->id,
                'data' => array_merge($this->notifications->aboutTask($waiter, $project), ['note' => $doneTask->code]),
            ]);
        }
    }

    /**
     * @param  int[]  $dependsOn
     * @param  Collection<int, Task>  $projectTasks
     */
    private function makesLoop(int $taskId, array $dependsOn, Collection $projectTasks): bool
    {
        $seen = [];
        $stack = $dependsOn;
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if ($id === $taskId) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $t = $projectTasks->get($id);
            if ($t) {
                foreach ($t->dependencies as $dep) {
                    $stack[] = (int) $dep->id;
                }
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function approverRecipientIds(Project $project): array
    {
        $memberIds = $project->memberIds();

        return User::query()
            ->where('active', true)
            ->where('role', '!=', 'ceo')
            ->where(function ($q) use ($project, $memberIds) {
                $q->where('id', $project->pm_id)
                    ->orWhere(function ($q2) use ($memberIds) {
                        $q2->where('role', 'lead')->whereIn('id', $memberIds);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function logEvent(int $taskId, int $actorId, string $kind, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        TaskEvent::create([
            'task_id' => $taskId,
            'actor_id' => $actorId,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'at' => now('UTC'),
        ]);
    }
}
