<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Bug;
use App\Models\BugEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BugService
{
    public const LIMITS = ['title' => 160, 'description' => 5000, 'note' => 300];

    private const NEXT = [
        'reported' => ['assigned'],
        'assigned' => ['fixing'],
        'fixing' => ['testing'],
        'testing' => ['closed', 'reopened'],
        'reopened' => ['fixing'],
        'closed' => ['reopened'],
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly CodeGenerator $codes,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return Collection<int, Bug>
     */
    public function list(User $user): Collection
    {
        $this->advanced->requireFeature($user, 'bugs');
        $visibleIds = Project::query()
            ->with('members:id')
            ->get()
            ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
            ->pluck('id')
            ->all();

        if ($visibleIds === []) {
            return collect();
        }

        return Bug::query()->whereIn('project_id', $visibleIds)->orderBy('id')->get();
    }

    public function show(User $user, int $id): Bug
    {
        return $this->bugForOrFail($user, $id)['bug'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function report(User $user, int $projectId, array $input): Bug
    {
        $this->advanced->requireFeature($user, 'bugs');
        $project = $this->access->projectForOrFail($user, $projectId);
        if (! $this->access->canReportBug($user, $project)) {
            throw ApiException::forbidden('api.bugs.report_forbidden');
        }
        $this->advanced->requireAction($user, 'report_bugs');

        $fields = $input;
        if (empty($fields['severity'])) {
            throw ApiException::validation('severity', 'api.bugs.severity_invalid');
        }

        if (! $this->access->canManageBugs($user, $project)) {
            unset($fields['assignee_id'], $fields['due']);
        }

        $this->checkFields($project, $fields, true);

        return DB::transaction(function () use ($user, $project, $fields) {
            $assigneeId = ! empty($fields['assignee_id']) ? (int) $fields['assignee_id'] : null;

            $bug = Bug::create([
                'code' => $this->codes->nextBugCode(),
                'project_id' => $project->id,
                'phase_id' => ! empty($fields['phase_id']) ? (int) $fields['phase_id'] : null,
                'task_id' => ! empty($fields['task_id']) ? (int) $fields['task_id'] : null,
                'title' => trim((string) $fields['title']),
                'description' => trim((string) ($fields['description'] ?? '')),
                'severity' => $fields['severity'],
                'priority' => $fields['priority'],
                'status' => $assigneeId !== null ? 'assigned' : 'reported',
                'reported_by' => $user->id,
                'assignee_id' => $assigneeId,
                'due' => ! empty($fields['due']) ? $fields['due'] : null,
            ]);

            $this->logEvent($bug->id, $user->id, 'created');

            $managers = $this->managerIds($project);
            $this->notifications->notify($managers, 'bug_reported', $this->about($bug, $user->id));
            if ($bug->assignee_id !== null) {
                $this->notifications->notify([$bug->assignee_id], 'bug_assigned', $this->about($bug, $user->id));
            }

            return $bug;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(User $user, int $id, array $patch): Bug
    {
        ['bug' => $bug, 'project' => $project] = $this->bugForOrFail($user, $id);
        if (! $this->access->canManageBugs($user, $project)) {
            throw ApiException::forbidden('api.bugs.edit_forbidden');
        }

        $this->checkFields($project, $patch, false);

        return DB::transaction(function () use ($user, $bug, $patch) {
            if (array_key_exists('severity', $patch) && $patch['severity'] !== $bug->severity) {
                $this->logEvent($bug->id, $user->id, 'severity', $bug->severity, $patch['severity']);
                $bug->severity = $patch['severity'];
            }
            if (array_key_exists('priority', $patch) && $patch['priority'] !== $bug->priority) {
                $this->logEvent($bug->id, $user->id, 'priority', $bug->priority, $patch['priority']);
                $bug->priority = $patch['priority'];
            }
            if (array_key_exists('due', $patch)) {
                $due = ! empty($patch['due']) ? (string) $patch['due'] : null;
                if ($due !== $bug->due) {
                    $this->logEvent($bug->id, $user->id, 'due', $bug->due, $due);
                    $bug->due = $due;
                }
            }
            if (array_key_exists('assignee_id', $patch)) {
                $nextAssignee = $patch['assignee_id'] !== null ? (int) $patch['assignee_id'] : null;
                $prevAssignee = $bug->assignee_id !== null ? (int) $bug->assignee_id : null;
                if ($nextAssignee !== $prevAssignee) {
                    $this->logEvent(
                        $bug->id,
                        $user->id,
                        'assignee',
                        $prevAssignee !== null ? (string) $prevAssignee : null,
                        $nextAssignee !== null ? (string) $nextAssignee : null
                    );
                    $bug->assignee_id = $nextAssignee;
                    if ($nextAssignee !== null) {
                        $this->notifications->notify([$nextAssignee], 'bug_assigned', $this->about($bug, $user->id));
                        if ($bug->status === 'reported') {
                            $this->logEvent($bug->id, $user->id, 'status', 'reported', 'assigned');
                            $bug->status = 'assigned';
                        }
                    }
                }
            }

            $edited = [];
            if (array_key_exists('title', $patch) && trim((string) $patch['title']) !== $bug->title) {
                $bug->title = trim((string) $patch['title']);
                $edited[] = 'title';
            }
            if (array_key_exists('description', $patch) && (string) $patch['description'] !== $bug->description) {
                $bug->description = (string) $patch['description'];
                $edited[] = 'description';
            }
            if ($edited !== []) {
                $this->logEvent($bug->id, $user->id, 'edited', null, implode(',', $edited));
            }

            if (array_key_exists('phase_id', $patch)) {
                $bug->phase_id = $patch['phase_id'] !== null ? (int) $patch['phase_id'] : null;
            }
            if (array_key_exists('task_id', $patch)) {
                $bug->task_id = $patch['task_id'] !== null ? (int) $patch['task_id'] : null;
            }

            $bug->save();

            return $bug->fresh();
        });
    }

    public function move(User $user, int $id, string $status, ?string $note = null): Bug
    {
        ['bug' => $bug, 'project' => $project] = $this->bugForOrFail($user, $id);

        if (! in_array($status, self::NEXT[$bug->status] ?? [], true)) {
            throw ApiException::validation('status', 'api.bugs.status_invalid_transition');
        }

        if (! in_array($status, $this->access->bugNextStatuses($user, $project, $bug), true)) {
            throw ApiException::forbidden('api.bugs.move_forbidden');
        }

        if ($status === 'assigned' && $bug->assignee_id === null) {
            throw ApiException::validation('assignee_id', 'api.bugs.assignee_required');
        }

        $reason = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($status === 'reopened' && $reason === null) {
            throw ApiException::validation('note', 'api.bugs.reopen_note_required');
        }
        if ($reason !== null && mb_strlen($reason) > self::LIMITS['note']) {
            throw ApiException::validation('note', 'api.bugs.note_max');
        }

        return DB::transaction(function () use ($user, $bug, $project, $status, $reason) {
            $from = $bug->status;
            $bug->status = $status;
            $bug->closed_at = $status === 'closed' ? now('UTC') : null;
            $bug->save();

            $this->logEvent($bug->id, $user->id, 'status', $from, $status, $reason);

            $about = $this->about($bug, $user->id);
            $about['data']['bug_status'] = $status;
            if ($reason !== null) {
                $about['data']['note'] = $reason;
            }

            $this->notifications->notify([$bug->reported_by, $bug->assignee_id, $project->pm_id], 'bug_status', $about);

            return $bug->fresh();
        });
    }

    /**
     * @return Collection<int, BugEvent>
     */
    public function history(User $user, int $id): Collection
    {
        $this->bugForOrFail($user, $id);

        return BugEvent::query()->where('bug_id', $id)->orderBy('id')->get();
    }

    /**
     * @return array{bug: Bug, project: Project}
     */
    private function bugForOrFail(User $user, int $id): array
    {
        $bug = Bug::query()->with(['project.members:id', 'project.statuses', 'project.phases'])->find($id);
        if (! $bug || ! $bug->project || ! $this->access->canSeeProject($user, $bug->project)) {
            throw ApiException::notFound('api.bugs.not_found');
        }

        return ['bug' => $bug, 'project' => $bug->project];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function checkFields(Project $project, array $fields, bool $isCreate): void
    {
        if ($isCreate || array_key_exists('title', $fields)) {
            $title = trim((string) ($fields['title'] ?? ''));
            if ($title === '') {
                throw ApiException::validation('title', 'api.bugs.title_required');
            }
            if (mb_strlen($title) > self::LIMITS['title']) {
                throw ApiException::validation('title', 'api.bugs.title_max');
            }
        }

        if (array_key_exists('description', $fields) && $fields['description'] !== null && mb_strlen((string) $fields['description']) > self::LIMITS['description']) {
            throw ApiException::validation('description', 'api.bugs.description_max');
        }

        if (array_key_exists('severity', $fields) && ! in_array($fields['severity'], Bug::SEVERITIES, true)) {
            throw ApiException::validation('severity', 'api.bugs.severity_invalid');
        }

        if (array_key_exists('priority', $fields) && ! in_array($fields['priority'], Bug::PRIORITIES, true)) {
            throw ApiException::validation('priority', 'api.bugs.priority_invalid');
        }

        if (array_key_exists('assignee_id', $fields) && $fields['assignee_id'] !== null) {
            if (! in_array((int) $fields['assignee_id'], $project->memberIds(), true)) {
                throw ApiException::validation('assignee_id', 'api.bugs.assignee_not_member');
            }
        }

        if (array_key_exists('due', $fields) && $fields['due'] !== null && $fields['due'] !== '') {
            if (! is_string($fields['due']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['due']) !== 1) {
                throw ApiException::validation('due', 'api.bugs.due_invalid');
            }
            [$y, $m, $d] = array_map('intval', explode('-', $fields['due']));
            if (! checkdate($m, $d, $y)) {
                throw ApiException::validation('due', 'api.bugs.due_invalid');
            }
        }

        if (array_key_exists('phase_id', $fields) && $fields['phase_id'] !== null) {
            if (! $project->phases->contains('id', (int) $fields['phase_id'])) {
                throw ApiException::validation('phase_id', 'api.bugs.phase_invalid');
            }
        }

        if (array_key_exists('task_id', $fields) && $fields['task_id'] !== null) {
            $exists = Task::query()
                ->where('id', (int) $fields['task_id'])
                ->where('project_id', $project->id)
                ->exists();
            if (! $exists) {
                throw ApiException::validation('task_id', 'api.bugs.task_invalid');
            }
        }
    }

    /**
     * @return int[]
     */
    private function managerIds(Project $project): array
    {
        $memberIds = $project->memberIds();

        return User::query()
            ->where('active', true)
            ->where(function ($q) use ($project, $memberIds) {
                $q->whereIn('role', ['ceo', 'monitor'])
                    ->orWhere('id', $project->pm_id)
                    ->orWhere(function ($q2) use ($memberIds) {
                        $q2->where('role', 'lead')->whereIn('id', $memberIds);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function about(Bug $bug, int $actorId): array
    {
        return [
            'actor_id' => $actorId,
            'project_id' => $bug->project_id,
            'bug_id' => $bug->id,
            'data' => [
                'bug_code' => $bug->code,
                'bug_title' => $bug->title,
            ],
        ];
    }

    private function logEvent(int $bugId, int $actorId, string $kind, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        BugEvent::create([
            'bug_id' => $bugId,
            'actor_id' => $actorId,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'at' => now('UTC'),
        ]);
    }
}
