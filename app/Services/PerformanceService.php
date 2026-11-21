<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\PerformanceCriteria;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewScore;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PerformanceService
{
    public const NOTE_LIMIT = 3000;

    public const METRICS = ['completion', 'on_time', 'not_late'];

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null}  $period
     * @return array{people: array<int, array<string, mixed>>, teams: array<int, array<string, mixed>>}
     */
    public function overview(User $user, array $period): array
    {
        if (! $this->access->canSeePeoplePerf($user)) {
            throw ApiException::forbidden('api.performance.forbidden');
        }

        $validPeriod = $this->checkPeriod($period);
        $today = BaghdadTime::today();

        $allProjects = Project::query()->with(['members:id', 'statuses'])->get();
        $visibleProjects = $allProjects->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))->values();
        $visibleIds = $visibleProjects->pluck('id')->all();

        $tasks = $visibleIds !== []
            ? Task::query()->whereIn('project_id', $visibleIds)->with('currentApproval')->get()
            : collect();

        $users = User::query()->orderBy('id')->get();
        $teams = Team::query()->orderBy('id')->get();

        $people = $users
            ->filter(fn (User $u) => $u->active
                && (int) $u->id !== (int) $user->id
                && ! in_array($u->role, ['ceo', 'admin'], true)
                && $this->access->canSeePerson($user, $u, $allProjects))
            ->map(fn (User $u) => $this->personPerf($u->id, $u->team_id, $tasks, $visibleProjects, $validPeriod, $today))
            ->values()
            ->all();

        $teamRows = [];
        foreach ($teams as $team) {
            $row = $this->teamPerf($team, $users, $people, $visibleProjects, $tasks, $validPeriod);
            if ($row['assigned'] > 0) {
                $teamRows[] = $row;
            }
        }

        return [
            'people' => $people,
            'teams' => $teamRows,
        ];
    }

    /**
     * @param  array{from?: string|null, to?: string|null}  $period
     * @return array<string, mixed>
     */
    public function person(User $user, int $subjectId, array $period): array
    {
        $validPeriod = $this->checkPeriod($period);
        $allProjects = Project::query()->with(['members:id', 'statuses'])->get();
        $subject = User::query()->find($subjectId);

        if (! $subject || ! $this->access->canSeePerson($user, $subject, $allProjects)) {
            throw ApiException::notFound('api.performance.person_not_found');
        }

        $visibleProjects = $allProjects->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))->values();
        $visibleIds = $visibleProjects->pluck('id')->all();

        $tasks = $visibleIds !== []
            ? Task::query()->whereIn('project_id', $visibleIds)->where('assignee_id', $subject->id)->with('currentApproval')->get()
            : collect();

        $today = BaghdadTime::today();
        $perf = $this->personPerf($subject->id, $subject->team_id, $tasks, $visibleProjects, $validPeriod, $today);
        $blocked = $this->blockedTasks($subject->id, $tasks, $visibleProjects);

        $reviews = PerformanceReview::query()
            ->where('user_id', $subject->id)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PerformanceReview $r) => $this->access->canSeeReview($user, (int) $r->reviewer_id, $r->state, $subject, $allProjects))
            ->values();

        return [
            'perf' => $perf,
            'blocked' => $blocked,
            'reviews' => $reviews,
            'can_review' => $this->access->canReview($user, $subject, $allProjects) && $this->advanced->roleMay($user, 'write_reviews'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function criteria(): array
    {
        return $this->formatCriteria(PerformanceCriteria::current());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function saveCriteria(User $user, array $items): array
    {
        if (! $this->access->canManageCriteria($user)) {
            throw ApiException::forbidden('api.performance.criteria_forbidden');
        }

        $clean = [];
        foreach ($items as $i => $c) {
            $kind = ($c['kind'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
            $item = [
                'id' => ! empty($c['id']) ? (string) $c['id'] : 'c'.base_convert((string) now()->getTimestampMs(), 10, 36).$i,
                'name' => trim((string) ($c['name'] ?? '')),
                'name_en' => trim((string) ($c['name_en'] ?? '')),
                'kind' => $kind,
                'weight' => $c['weight'] ?? 0,
            ];
            if ($kind === 'auto' && ! empty($c['metric'])) {
                $item['metric'] = (string) $c['metric'];
            }
            $clean[] = $item;
        }

        $problem = $this->criteriaProblem($clean);
        if ($problem !== null) {
            throw ApiException::validation('items', "api.performance.criteria_{$problem}");
        }

        return DB::transaction(function () use ($user, $clean) {
            $current = PerformanceCriteria::current();
            $from = collect($current->items ?? [])->map(fn ($c) => "{$c['name']} {$c['weight']}")->implode('، ');
            $to = collect($clean)->map(fn ($c) => "{$c['name']} {$c['weight']}")->implode('، ');

            $this->audit->log($user->id, 'criteria_changed', [
                'type' => 'criteria',
                'label' => 'معايير التقييم',
            ], [[
                'field' => 'items',
                'from' => $from,
                'to' => $to,
            ]]);

            $current->items = $clean;
            $current->approved_by = null;
            $current->approved_at = null;
            $current->updated_at = now('UTC');
            $current->save();

            return $this->formatCriteria($current);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function approveCriteria(User $user): array
    {
        if (! $this->access->canManageCriteria($user)) {
            throw ApiException::forbidden('api.performance.criteria_approve_forbidden');
        }

        $current = PerformanceCriteria::current();
        if ($this->criteriaProblem($current->items ?? []) !== null) {
            throw ApiException::validation('items', 'api.performance.criteria_fix_before_approve');
        }

        return DB::transaction(function () use ($user, $current) {
            $now = now('UTC');
            $current->approved_by = $user->id;
            $current->approved_at = $now;
            $current->save();

            $this->audit->log($user->id, 'criteria_approved', [
                'type' => 'criteria',
                'label' => 'معايير التقييم',
            ]);

            return $this->formatCriteria($current);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveReview(User $user, array $input, ?int $reviewId = null): PerformanceReview
    {
        $this->advanced->requireFeature($user, 'reviews');
        $this->advanced->requireAction($user, 'write_reviews');

        $allProjects = Project::query()->with(['members:id', 'statuses'])->get();
        $subject = User::query()->find((int) ($input['user_id'] ?? 0));

        if (! $subject || ! $this->access->canReview($user, $subject, $allProjects)) {
            throw ApiException::forbidden('api.performance.review_forbidden');
        }

        $period = $this->checkPeriod((array) ($input['period'] ?? []));
        $today = BaghdadTime::today();
        if ($period['to'] > $today) {
            throw ApiException::validation('period', 'api.performance.period_not_ended');
        }

        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > self::NOTE_LIMIT) {
            throw ApiException::validation('note', 'api.performance.note_max');
        }

        $criteria = PerformanceCriteria::current()->items ?? [];
        $stars = (array) ($input['stars'] ?? []);
        foreach ($criteria as $c) {
            if (($c['kind'] ?? 'manual') === 'manual' && array_key_exists($c['id'], $stars) && $stars[$c['id']] !== null) {
                $val = $stars[$c['id']];
                if (! is_int($val) || $val < 1 || $val > 5) {
                    throw ApiException::validation("stars.{$c['id']}", 'api.performance.stars_range');
                }
            }
        }

        $visibleProjects = $allProjects->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))->values();
        $visibleIds = $visibleProjects->pluck('id')->all();
        $tasks = $visibleIds !== []
            ? Task::query()->whereIn('project_id', $visibleIds)->where('assignee_id', $subject->id)->with('currentApproval')->get()
            : collect();

        $perf = $this->personPerf($subject->id, $subject->team_id, $tasks, $visibleProjects, $period, $today);
        ['scores' => $scores, 'total' => $total] = $this->scoreReview($criteria, $perf, $stars);

        return DB::transaction(function () use ($user, $subject, $period, $scores, $criteria, $total, $note, $perf, $stars, $reviewId) {
            if ($reviewId !== null) {
                $review = PerformanceReview::query()->lockForUpdate()->find($reviewId);
                if (! $review || (int) $review->reviewer_id !== (int) $user->id) {
                    throw ApiException::notFound('api.performance.review_not_found');
                }
                if ($review->state === 'final') {
                    throw ApiException::badState('api.performance.review_final_immutable');
                }

                $review->update([
                    'period_from' => $period['from'],
                    'period_to' => $period['to'],
                    'scores' => $scores,
                    'criteria' => $criteria,
                    'total' => $total,
                    'note' => $note,
                    'perf' => $perf,
                ]);
            } else {
                $review = PerformanceReview::create([
                    'user_id' => $subject->id,
                    'reviewer_id' => $user->id,
                    'period_from' => $period['from'],
                    'period_to' => $period['to'],
                    'scores' => $scores,
                    'criteria' => $criteria,
                    'total' => $total,
                    'note' => $note,
                    'perf' => $perf,
                    'state' => 'draft',
                ]);
            }

            $this->syncScoreRows($review, $criteria, $scores, $stars);

            return $review->fresh();
        });
    }

    public function finalizeReview(User $user, int $reviewId): PerformanceReview
    {
        $this->advanced->requireFeature($user, 'reviews');
        $this->advanced->requireAction($user, 'write_reviews');

        return DB::transaction(function () use ($user, $reviewId) {
            $review = PerformanceReview::query()->lockForUpdate()->find($reviewId);
            if (! $review || (int) $review->reviewer_id !== (int) $user->id) {
                throw ApiException::notFound('api.performance.review_not_found');
            }
            if ($review->state === 'final') {
                throw ApiException::badState('api.performance.review_already_final');
            }

            $criteriaModel = PerformanceCriteria::current();
            if ($criteriaModel->approved_at === null) {
                throw ApiException::badState('api.performance.criteria_not_approved');
            }

            $scores = $review->scores ?? [];
            foreach ($review->criteria ?? [] as $c) {
                if (($c['kind'] ?? 'manual') === 'manual' && empty($scores[$c['id']])) {
                    throw ApiException::validation('stars', 'api.performance.stars_missing');
                }
            }

            $review->state = 'final';
            $review->finalized_at = now('UTC');
            $review->save();

            $subjectName = User::query()->find($review->user_id)?->name ?? '-';
            $this->audit->log($user->id, 'review_finalized', [
                'type' => 'review',
                'id' => $review->id,
                'label' => $subjectName,
            ], [[
                'field' => 'total',
                'to' => (string) $review->total,
            ]]);

            $this->notifications->notify([$review->user_id], 'review_final', [
                'actor_id' => $user->id,
                'data' => ['note' => "{$review->period_from} → {$review->period_to}"],
            ]);

            return $review->fresh();
        });
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Project>  $projects
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    public function computePersonPerf(int $userId, ?int $teamId, Collection $tasks, Collection $projects, array $period, string $today): array
    {
        return $this->personPerf($userId, $teamId, $tasks, $projects, $period, $today);
    }

    public function taskFinishedDay(Project $project, Task $task): ?string
    {
        $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
        if (! in_array($task->status, $doneKeys, true)) {
            return null;
        }
        $dt = ($task->currentApproval && $task->currentApproval->state === 'approved' && $task->currentApproval->decided_at)
            ? $task->currentApproval->decided_at
            : $task->updated_at;

        return $dt ? BaghdadTime::toBaghdadDate($dt) : null;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Project>  $projects
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function personPerf(int $userId, ?int $teamId, Collection $tasks, Collection $projects, array $period, string $today): array
    {
        $projectOf = $projects->keyBy('id');
        $end = $period['to'] < $today ? $period['to'] : $today;

        $assigned = 0;
        $done = 0;
        $late = 0;
        $blocked = 0;
        $withDue = 0;
        $onTime = 0;

        foreach ($tasks as $t) {
            if ((int) $t->assignee_id !== $userId) {
                continue;
            }
            $project = $projectOf->get($t->project_id);
            if (! $project) {
                continue;
            }

            $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
            $blockedKeys = $project->statuses->where('tone', 'blocked')->pluck('key')->all();

            $finished = null;
            if (in_array($t->status, $doneKeys, true)) {
                $dt = ($t->currentApproval && $t->currentApproval->state === 'approved' && $t->currentApproval->decided_at)
                    ? $t->currentApproval->decided_at
                    : $t->updated_at;
                $finished = $dt ? BaghdadTime::toBaghdadDate($dt) : null;
            }

            $createdDay = $t->created_at ? BaghdadTime::toBaghdadDate($t->created_at) : '';
            if ($createdDay > $period['to'] || ($finished !== null && $finished < $period['from'])) {
                continue;
            }

            $assigned++;
            if ($finished === null && in_array($t->status, $blockedKeys, true)) {
                $blocked++;
            }

            if ($finished !== null && $finished <= $period['to']) {
                $done++;
                if ($t->due !== null) {
                    $withDue++;
                    if ($finished <= $t->due) {
                        $onTime++;
                    } else {
                        $late++;
                    }
                }
            } elseif ($t->due !== null && $t->due < $end) {
                $late++;
            }
        }

        return ApiResponse::clean([
            'user_id' => $userId,
            'team_id' => $teamId !== null ? (int) $teamId : null,
            'assigned' => $assigned,
            'done' => $done,
            'late' => $late,
            'blocked' => $blocked,
            'completion' => $assigned > 0 ? $done / $assigned : 0,
            'on_time' => $withDue > 0 ? $onTime / $withDue : null,
        ], ['on_time']);
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function blockedTasks(int $userId, Collection $tasks, Collection $projects): array
    {
        $projectOf = $projects->keyBy('id');
        $out = [];

        foreach ($tasks as $t) {
            if ((int) $t->assignee_id !== $userId) {
                continue;
            }
            $project = $projectOf->get($t->project_id);
            if (! $project) {
                continue;
            }
            $blockedKeys = $project->statuses->where('tone', 'blocked')->pluck('key')->all();
            if (! in_array($t->status, $blockedKeys, true)) {
                continue;
            }
            $out[] = ApiResponse::clean([
                'task_id' => (int) $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'reason' => $t->blocked_reason,
                'project_id' => (int) $t->project_id,
            ]);
        }

        return $out;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int, array<string, mixed>>  $people
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Task>  $tasks
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function teamPerf(Team $team, Collection $users, array $people, Collection $projects, Collection $tasks, array $period): array
    {
        $rows = array_filter($people, fn ($r) => ($r['team_id'] ?? null) === (int) $team->id);
        $memberIds = $users->filter(fn (User $u) => (int) $u->team_id === (int) $team->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $teamProjects = $projects->filter(fn (Project $p) => $tasks->contains(
            fn (Task $t) => (int) $t->project_id === (int) $p->id && $t->assignee_id !== null && in_array((int) $t->assignee_id, $memberIds, true)
        ));

        $assigned = array_sum(array_column($rows, 'assigned'));
        $done = array_sum(array_column($rows, 'done'));
        $late = array_sum(array_column($rows, 'late'));
        $blocked = array_sum(array_column($rows, 'blocked'));
        $membersCount = $users->filter(fn (User $u) => $u->active && (int) $u->team_id === (int) $team->id)->count();

        $planned = $teamProjects->isNotEmpty()
            ? $teamProjects->sum(fn (Project $p) => BaghdadTime::plannedRatio($p->start, $p->end, $period['to'])) / $teamProjects->count()
            : 0;

        return ApiResponse::clean([
            'team_id' => (int) $team->id,
            'name' => $team->name,
            'name_en' => $team->name_en,
            'members' => $membersCount,
            'assigned' => $assigned,
            'done' => $done,
            'left' => $assigned - $done,
            'late' => $late,
            'blocked' => $blocked,
            'completion' => $assigned > 0 ? $done / $assigned : 0,
            'planned' => $planned,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<string, mixed>  $perf
     * @param  array<string, mixed>  $stars
     * @return array{scores: array<string, int>, total: int}
     */
    private function scoreReview(array $criteria, array $perf, array $stars): array
    {
        $scores = [];
        $weightedSum = 0.0;

        foreach ($criteria as $c) {
            if (($c['kind'] ?? 'manual') === 'auto' && ! empty($c['metric'])) {
                $score = $this->metricScore($c['metric'], $perf);
            } else {
                $score = ((int) ($stars[$c['id']] ?? 0)) * 20;
            }
            $scores[$c['id']] = $score;
            $weightedSum += ($score * (int) $c['weight']) / 100.0;
        }

        return [
            'scores' => $scores,
            'total' => (int) round($weightedSum, 0, PHP_ROUND_HALF_UP),
        ];
    }

    /**
     * @param  array<string, mixed>  $perf
     */
    private function metricScore(string $metric, array $perf): int
    {
        if ($metric === 'completion') {
            return (int) round($perf['completion'] * 100, 0, PHP_ROUND_HALF_UP);
        }
        if ($metric === 'on_time') {
            $val = $perf['on_time'] ?? $perf['completion'];

            return (int) round($val * 100, 0, PHP_ROUND_HALF_UP);
        }

        return $perf['assigned'] > 0
            ? (int) round((1 - $perf['late'] / $perf['assigned']) * 100, 0, PHP_ROUND_HALF_UP)
            : 100;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function criteriaProblem(array $items): ?string
    {
        if ($items === []) {
            return 'empty';
        }
        foreach ($items as $c) {
            if (trim((string) ($c['name'] ?? '')) === '' || trim((string) ($c['name_en'] ?? '')) === '') {
                return 'name';
            }
        }
        foreach ($items as $c) {
            $w = $c['weight'] ?? null;
            if (! is_int($w) || $w <= 0) {
                return 'weight';
            }
        }
        if (array_sum(array_column($items, 'weight')) !== 100) {
            return 'sum';
        }
        foreach ($items as $c) {
            if (($c['kind'] ?? 'manual') === 'auto' && ! in_array($c['metric'] ?? null, self::METRICS, true)) {
                return 'metric';
            }
        }
        $hasManual = collect($items)->contains(fn ($c) => ($c['kind'] ?? 'manual') === 'manual');
        if (! $hasManual) {
            return 'only_auto';
        }

        return null;
    }

    /**
     * @param  array{from?: mixed, to?: mixed}  $period
     * @return array{from: string, to: string}
     */
    private function checkPeriod(array $period): array
    {
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;
        if (! $this->isDay($from) || ! $this->isDay($to)) {
            throw ApiException::validation('period', 'api.performance.period_invalid');
        }
        if ($from > $to) {
            throw ApiException::validation('period', 'api.performance.period_order');
        }

        return ['from' => $from, 'to' => $to];
    }

    private function isDay(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCriteria(PerformanceCriteria $criteria): array
    {
        return ApiResponse::clean([
            'items' => $criteria->items ?? [],
            'approved_by' => $criteria->approved_by !== null ? (int) $criteria->approved_by : null,
            'approved_at' => BaghdadTime::iso($criteria->approved_at),
            'updated_at' => BaghdadTime::iso($criteria->updated_at),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $criteria
     * @param  array<string, int>  $scores
     * @param  array<string, mixed>  $stars
     */
    private function syncScoreRows(PerformanceReview $review, array $criteria, array $scores, array $stars): void
    {
        PerformanceReviewScore::query()->where('review_id', $review->id)->delete();

        foreach ($criteria as $c) {
            PerformanceReviewScore::create([
                'review_id' => $review->id,
                'criterion_id' => (string) $c['id'],
                'criterion_name' => (string) $c['name'],
                'criterion_name_en' => (string) $c['name_en'],
                'kind' => (string) $c['kind'],
                'metric' => $c['metric'] ?? null,
                'weight' => (int) $c['weight'],
                'stars' => ($c['kind'] === 'manual' && isset($stars[$c['id']])) ? (int) $stars[$c['id']] : null,
                'score' => (int) ($scores[$c['id']] ?? 0),
            ]);
        }
    }
}
