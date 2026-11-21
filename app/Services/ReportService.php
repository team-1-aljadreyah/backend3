<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Bug;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;

class ReportService
{
    public const BEHIND_BY = 0.15;

    public function __construct(private readonly AccessService $access) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        if (! $this->access->canSeeReports($user)) {
            throw ApiException::forbidden('api.reports.forbidden');
        }

        $today = BaghdadTime::today();

        $projects = Project::query()
            ->with(['members:id', 'statuses'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
            ->values();

        $projectIds = $projects->pluck('id')->all();
        $projectOf = $projects->keyBy('id');

        $tasks = $projectIds !== []
            ? Task::query()->whereIn('project_id', $projectIds)->get()
            : collect();

        $users = User::query()->get()->keyBy('id');
        $teams = Team::query()->orderBy('id')->get();
        $sprints = $projectIds !== []
            ? Sprint::query()->whereIn('project_id', $projectIds)->orderBy('start')->get()
            : collect();
        $bugs = $projectIds !== []
            ? Bug::query()->whereIn('project_id', $projectIds)->get()
            : collect();

        $projectRows = $projects->map(function (Project $project) use ($tasks, $today) {
            $mine = $tasks->where('project_id', $project->id);
            $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
            $blockedKeys = $project->statuses->where('tone', 'blocked')->pluck('key')->all();

            $total = $mine->count();
            $done = $mine->filter(fn (Task $t) => in_array($t->status, $doneKeys, true))->count();
            $late = $mine->filter(fn (Task $t) => $t->due !== null && $t->due < $today && ! in_array($t->status, $doneKeys, true))->count();
            $blocked = $mine->filter(fn (Task $t) => in_array($t->status, $blockedKeys, true))->count();
            $ratio = $total > 0 ? $done / $total : 0;
            $planned = BaghdadTime::plannedRatio($project->start, $project->end, $today);

            return ApiResponse::clean([
                'id' => (int) $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'name_en' => $project->name_en,
                'state' => $project->state,
                'pm_id' => (int) $project->pm_id,
                'start' => $project->start,
                'end' => $project->end,
                'total' => $total,
                'done' => $done,
                'late' => $late,
                'blocked' => $blocked,
                'ratio' => $ratio,
                'planned_ratio' => $planned,
                'overdue' => $project->state !== 'done' && $project->end < $today,
                'behind' => $project->state === 'active' && ($ratio + self::BEHIND_BY) < $planned,
            ]);
        })->values()->all();

        $byPerson = [];
        foreach ($tasks as $t) {
            if ($t->assignee_id === null) {
                continue;
            }
            $uid = (int) $t->assignee_id;
            $project = $projectOf->get($t->project_id);
            if (! $project) {
                continue;
            }
            $doneKeys = $project->statuses->where('tone', 'done')->pluck('key')->all();
            $blockedKeys = $project->statuses->where('tone', 'blocked')->pluck('key')->all();
            $reviewKeys = $project->statuses->where('tone', 'review')->pluck('key')->all();

            if (! isset($byPerson[$uid])) {
                $assignee = $users->get($uid);
                $byPerson[$uid] = ApiResponse::clean([
                    'user_id' => $uid,
                    'team_id' => $assignee?->team_id !== null ? (int) $assignee->team_id : null,
                    'open' => 0,
                    'done' => 0,
                    'late' => 0,
                    'blocked' => 0,
                    'in_review' => 0,
                ]);
            }

            if (in_array($t->status, $doneKeys, true)) {
                $byPerson[$uid]['done']++;
            } else {
                $byPerson[$uid]['open']++;
            }

            if ($t->due !== null && $t->due < $today && ! in_array($t->status, $doneKeys, true)) {
                $byPerson[$uid]['late']++;
            }
            if (in_array($t->status, $blockedKeys, true)) {
                $byPerson[$uid]['blocked']++;
            }
            if (in_array($t->status, $reviewKeys, true)) {
                $byPerson[$uid]['in_review']++;
            }
        }

        $people = array_values($byPerson);
        usort($people, fn ($a, $b) => ($b['late'] <=> $a['late']) ?: ($b['open'] <=> $a['open']));

        $teamRows = [];
        foreach ($teams as $team) {
            $rows = array_filter($people, fn ($r) => ($r['team_id'] ?? null) === (int) $team->id);
            $done = array_sum(array_column($rows, 'done'));
            $open = array_sum(array_column($rows, 'open'));
            if ($open + $done === 0) {
                continue;
            }
            $late = array_sum(array_column($rows, 'late'));
            $membersCount = $users->filter(fn (User $u) => $u->active && (int) $u->team_id === (int) $team->id)->count();

            $teamRows[] = ApiResponse::clean([
                'team_id' => (int) $team->id,
                'name' => $team->name,
                'name_en' => $team->name_en,
                'members' => $membersCount,
                'open' => $open,
                'done' => $done,
                'late' => $late,
                'ratio' => ($done + $open) > 0 ? $done / ($done + $open) : 0,
            ]);
        }

        $sprintRows = $sprints->map(function (Sprint $sprint) use ($projectOf, $tasks) {
            $project = $projectOf->get($sprint->project_id);
            if ($sprint->result !== null) {
                $planned = (int) ($sprint->result['planned'] ?? 0);
                $done = (int) ($sprint->result['done'] ?? 0);
                $left = $planned - $done;
            } else {
                $doneKeys = $project ? $project->statuses->where('tone', 'done')->pluck('key')->all() : [];
                $mine = $tasks->where('sprint_id', $sprint->id);
                $planned = $mine->count();
                $done = $mine->filter(fn (Task $t) => in_array($t->status, $doneKeys, true))->count();
                $left = $planned - $done;
            }

            return [
                'project_id' => (int) $sprint->project_id,
                'sprint_id' => (int) $sprint->id,
                'name' => $sprint->name,
                'start' => $sprint->start,
                'end' => $sprint->end,
                'closed' => $sprint->closed_at !== null,
                'planned' => $planned,
                'done' => $done,
                'left' => $left,
            ];
        })->values()->all();

        $openBugs = $bugs->filter(fn (Bug $b) => $b->status !== 'closed');
        $bugCounts = [
            'open' => $openBugs->count(),
            'late' => $openBugs->filter(fn (Bug $b) => $b->due !== null && $b->due < $today)->count(),
            'closed' => $bugs->filter(fn (Bug $b) => $b->status === 'closed')->count(),
            'critical_open' => $openBugs->filter(fn (Bug $b) => $b->severity === 'critical')->count(),
        ];

        return [
            'today' => $today,
            'projects' => $projectRows,
            'people' => $people,
            'teams' => $teamRows,
            'sprints' => $sprintRows,
            'bugs' => $bugCounts,
        ];
    }
}
