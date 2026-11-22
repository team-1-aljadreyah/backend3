<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\RatingResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\Rating;
use App\Models\Team;
use App\Models\Task;
use App\Models\User;
use App\Support\BaghdadTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RatingService
{
    public const LIMITS = ['reason' => 1000, 'editHours' => 24, 'trendDays' => 90];

    public const SCORE_WORDS_AR = [
        1 => 'ضعيف',
        2 => 'مقبول',
        3 => 'جيد',
        4 => 'جيد جداً',
        5 => 'ممتاز',
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function teamSummaries(User $user, Request $request): array
    {
        $teams = Team::query()->orderBy('id')->get();
        $activeUsers = User::query()->where('active', true)->orderBy('id')->get();
        $projects = Project::query()->with('members:id')->get();
        $allTeamRatings = Rating::query()
            ->where('subject_type', 'team')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('subject_id');

        $mayGive = $this->advanced->roleMay($user, 'give_ratings');
        $rows = [];

        foreach ($teams as $team) {
            if (! $this->access->canSeeTeam($user, $team)) {
                continue;
            }

            $memberCount = $activeUsers->where('team_id', (int) $team->id)->count();
            $sees = $this->access->canSeeTeamRatings($user, $team, $activeUsers, $projects);
            $rates = $this->access->canRateTeam($user, $team, $activeUsers, $projects);

            $summary = null;
            if ($sees) {
                $visible = $allTeamRatings->get((int) $team->id, collect())
                    ->filter(fn (Rating $r) => $this->access->canSeeRating($user, $r))
                    ->values();
                $summary = $this->summarize($visible);
            }

            $rows[] = [
                'team' => (new TeamResource($team))->resolve($request),
                'member_count' => $memberCount,
                'ratings' => $summary,
                'can_rate' => $rates && $mayGive,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function teamDetail(User $user, int $teamId, Request $request): array
    {
        $team = Team::query()->find($teamId);
        if (! $team || ! $this->access->canSeeTeam($user, $team)) {
            throw ApiException::notFound('api.ratings.team_not_found');
        }

        $activeUsers = User::query()->where('active', true)->orderBy('id')->get();
        $projects = Project::query()->with(['members:id', 'statuses'])->get();

        $members = $activeUsers
            ->where('team_id', (int) $team->id)
            ->sort(function (User $a, User $b) use ($team) {
                $aLead = (int) $a->id === (int) ($team->lead_id ?? 0) ? 1 : 0;
                $bLead = (int) $b->id === (int) ($team->lead_id ?? 0) ? 1 : 0;
                if ($aLead !== $bLead) {
                    return $bLead <=> $aLead;
                }

                return strcmp((string) $a->name, (string) $b->name);
            })
            ->values();

        $canSeeRatings = $this->access->canSeeTeamRatings($user, $team, $activeUsers, $projects);

        $tasksSummary = null;
        if ($user->isCeo() || in_array($user->role, ['pm', 'monitor', 'lead'], true)) {
            $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
            $visibleProjects = $projects
                ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
                ->keyBy('id');

            $counts = ['open' => 0, 'late' => 0, 'blocked' => 0, 'done' => 0];
            if ($memberIds !== [] && $visibleProjects->isNotEmpty()) {
                $day = BaghdadTime::today();
                $tasks = Task::query()
                    ->whereIn('project_id', $visibleProjects->keys()->all())
                    ->whereIn('assignee_id', $memberIds)
                    ->get();

                foreach ($tasks as $t) {
                    /** @var Project|null $project */
                    $project = $visibleProjects->get((int) $t->project_id);
                    if (! $project) {
                        continue;
                    }
                    $tone = $project->statuses->firstWhere('key', $t->status)?->tone;
                    if ($tone === 'done') {
                        $counts['done']++;
                        continue;
                    }
                    $counts['open']++;
                    if ($tone === 'blocked') {
                        $counts['blocked']++;
                    }
                    if ($t->due !== null && $t->due < $day) {
                        $counts['late']++;
                    }
                }
            }
            $tasksSummary = $counts;
        }

        return [
            'team' => (new TeamResource($team))->resolve($request),
            'members' => UserResource::collection($members)->resolve($request),
            'can_see_ratings' => $canSeeRatings,
            'tasks' => $tasksSummary,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function peopleSummaries(User $user): array
    {
        $activeUsers = User::query()
            ->where('active', true)
            ->whereNotIn('role', ['ceo', 'admin'])
            ->orderBy('id')
            ->get();
        $projects = Project::query()->with('members:id')->get();
        $allPersonRatings = Rating::query()
            ->where('subject_type', 'person')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('subject_id');

        $mayGive = $this->advanced->roleMay($user, 'give_ratings');
        $rows = [];

        foreach ($activeUsers as $person) {
            if (! $this->access->canSeePersonRatings($user, $person, $projects)) {
                continue;
            }

            $visible = $allPersonRatings->get((int) $person->id, collect())
                ->filter(fn (Rating $r) => $this->access->canSeeRating($user, $r))
                ->values();

            $rows[] = [
                'user_id' => (int) $person->id,
                'ratings' => $this->summarize($visible),
                'can_rate' => $this->access->canRatePerson($user, $person, $projects) && $mayGive,
            ];
        }

        return $rows;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, can_rate: bool}
     */
    public function listForSubject(User $user, string $subjectType, int $subjectId, Request $request): array
    {
        $ctx = $this->subjectAccess($user, $subjectType, $subjectId);
        if (! $ctx['sees']) {
            throw ApiException::forbidden('api.ratings.forbidden_read');
        }

        $items = Rating::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Rating $r) => $this->access->canSeeRating($user, $r))
            ->values();

        return [
            'items' => RatingResource::collection($items)->resolve($request),
            'can_rate' => $ctx['rates'] && $this->advanced->roleMay($user, 'give_ratings'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, string $subjectType, int $subjectId, array $input): Rating
    {
        $ctx = $this->subjectAccess($user, $subjectType, $subjectId);
        if (! $ctx['rates']) {
            throw ApiException::forbidden('api.ratings.forbidden_rate');
        }

        $this->advanced->requireAction($user, 'give_ratings');
        $fields = $this->validateAndPrepare($user, $input);

        return DB::transaction(function () use ($user, $subjectType, $subjectId, $fields, $ctx) {
            $rating = Rating::create(array_merge([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'rater_id' => $user->id,
                'created_at' => now('UTC'),
            ], $fields));

            $this->tell($rating, $ctx, (int) $user->id);

            return $rating;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, int $id, array $input): Rating
    {
        $ctx = $this->ratingForOrFail($user, $id);
        /** @var Rating $rating */
        $rating = $ctx['rating'];
        $now = now('UTC');

        if ((int) $rating->rater_id !== (int) $user->id && ! $this->access->canChangeRating($user, $rating, $now)) {
            throw ApiException::forbidden('api.ratings.edit_writer_only');
        }
        if (! $this->access->canChangeRating($user, $rating, $now)) {
            throw ApiException::badState('api.ratings.edit_window_expired');
        }

        $fields = $this->validateAndPrepare($user, $input);

        return DB::transaction(function () use ($user, $rating, $fields, $ctx, $now) {
            $beforeScore = (int) $rating->score;
            $beforeType = (string) $rating->reason_type;
            $beforeReason = (string) $rating->reason;
            $beforePrivate = (bool) $rating->private;

            $rating->fill($fields);
            $rating->edited_at = $now;
            $rating->edited_by = (int) $rating->rater_id === (int) $user->id ? null : (int) $user->id;
            $rating->save();

            if ((int) $rating->rater_id !== (int) $user->id) {
                $changes = array_values(array_filter([
                    $beforeScore !== (int) $rating->score ? [
                        'field' => 'score',
                        'from' => $this->scoreText($beforeScore),
                        'to' => $this->scoreText((int) $rating->score),
                    ] : null,
                    $beforeType !== (string) $rating->reason_type ? [
                        'field' => 'reason_type',
                        'from' => $beforeType,
                        'to' => (string) $rating->reason_type,
                    ] : null,
                    mb_substr($beforeReason, 0, 200) !== mb_substr((string) $rating->reason, 0, 200) ? [
                        'field' => 'reason',
                        'from' => mb_substr($beforeReason, 0, 200),
                        'to' => mb_substr((string) $rating->reason, 0, 200),
                    ] : null,
                    $beforePrivate !== (bool) $rating->private ? [
                        'field' => 'private',
                        'from' => $beforePrivate ? 'true' : 'false',
                        'to' => $rating->private ? 'true' : 'false',
                    ] : null,
                ]));

                $this->audit->log($user->id, 'rating_changed', $this->auditTarget($rating, $ctx['label']), $changes);
            }

            if ($beforePrivate && ! $rating->private) {
                $this->tell($rating, $ctx, (int) $user->id);
            }

            return $rating->fresh();
        });
    }

    public function delete(User $user, int $id): void
    {
        $ctx = $this->ratingForOrFail($user, $id);
        /** @var Rating $rating */
        $rating = $ctx['rating'];
        $now = now('UTC');

        if ((int) $rating->rater_id !== (int) $user->id && ! $this->access->canChangeRating($user, $rating, $now)) {
            throw ApiException::forbidden('api.ratings.delete_writer_only');
        }
        if (! $this->access->canChangeRating($user, $rating, $now)) {
            throw ApiException::badState('api.ratings.delete_window_expired');
        }

        DB::transaction(function () use ($user, $rating, $ctx) {
            $raterName = User::query()->find($rating->rater_id)?->name ?? '-';
            $this->audit->log($user->id, 'rating_deleted', $this->auditTarget($rating, $ctx['label']), [
                ['field' => 'score', 'from' => $this->scoreText((int) $rating->score)],
                ['field' => 'reason_type', 'from' => (string) $rating->reason_type],
                ['field' => 'reason', 'from' => mb_substr((string) $rating->reason, 0, 200)],
                ['field' => 'rater', 'from' => $raterName],
            ]);

            $rating->delete();
        });
    }

    /**
     * @return array{team: Team|null, person: User|null, label: string, sees: bool, rates: bool}
     */
    private function subjectAccess(User $user, string $subjectType, int $subjectId): array
    {
        $projects = Project::query()->with('members:id')->get();
        if ($subjectType === 'team') {
            $team = Team::query()->find($subjectId);
            if (! $team || ! $this->access->canSeeTeam($user, $team)) {
                throw ApiException::notFound('api.ratings.team_not_found');
            }
            $activeUsers = User::query()->where('active', true)->get();

            return [
                'team' => $team,
                'person' => null,
                'label' => $team->name,
                'sees' => $this->access->canSeeTeamRatings($user, $team, $activeUsers, $projects),
                'rates' => $this->access->canRateTeam($user, $team, $activeUsers, $projects),
            ];
        }

        $person = User::query()->find($subjectId);
        if (! $person) {
            throw ApiException::notFound('api.not_found');
        }

        return [
            'team' => null,
            'person' => $person,
            'label' => $person->name,
            'sees' => $this->access->canSeePersonRatings($user, $person, $projects),
            'rates' => $this->access->canRatePerson($user, $person, $projects) && (bool) $person->active,
        ];
    }

    /**
     * @return array{rating: Rating, team: Team|null, person: User|null, label: string, sees: bool, rates: bool}
     */
    private function ratingForOrFail(User $user, int $id): array
    {
        $rating = Rating::query()->find($id);
        if (! $rating) {
            throw ApiException::notFound('api.ratings.not_found');
        }

        try {
            $ctx = $this->subjectAccess($user, (string) $rating->subject_type, (int) $rating->subject_id);
        } catch (ApiException) {
            throw ApiException::notFound('api.ratings.not_found');
        }

        if (! $ctx['sees'] || ! $this->access->canSeeRating($user, $rating)) {
            throw ApiException::notFound('api.ratings.not_found');
        }

        return array_merge(['rating' => $rating], $ctx);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateAndPrepare(User $user, array $input): array
    {
        $errors = [];

        $score = $input['score'] ?? null;
        if (! is_int($score) || $score < 1 || $score > 5) {
            $errors['score'] = __('api.ratings.score_invalid');
        }

        $reasonType = $input['reason_type'] ?? null;
        if (! is_string($reasonType) || ! in_array($reasonType, Rating::REASON_TYPES, true)) {
            $errors['reason_type'] = __('api.ratings.reason_type_invalid');
        }

        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if ($reason === '') {
            $errors['reason'] = __('api.ratings.reason_required');
        } elseif (mb_strlen($reason) > self::LIMITS['reason']) {
            $errors['reason'] = __('api.ratings.reason_max');
        }

        $period = $input['period'] ?? null;
        $periodFrom = null;
        $periodTo = null;
        if ($period !== null) {
            if (! is_array($period) || ! $this->isDay($period['from'] ?? null) || ! $this->isDay($period['to'] ?? null)) {
                $errors['period'] = __('api.ratings.period_invalid');
            } elseif ($period['from'] > $period['to']) {
                $errors['period'] = __('api.ratings.period_order');
            } else {
                $periodFrom = (string) $period['from'];
                $periodTo = (string) $period['to'];
            }
        }

        $project = null;
        if (array_key_exists('project_id', $input) && $input['project_id'] !== null) {
            if (! is_int($input['project_id'])) {
                $errors['project_id'] = __('api.ratings.project_invalid');
            } else {
                $project = Project::query()->with('members:id')->find((int) $input['project_id']);
                if (! $project || ! $this->access->canSeeProject($user, $project)) {
                    $errors['project_id'] = __('api.ratings.project_invalid');
                }
            }
        }

        if (array_key_exists('private', $input) && $input['private'] !== null && ! is_bool($input['private'])) {
            $errors['private'] = __('api.ratings.private_invalid');
        }

        if ($errors !== []) {
            $first = reset($errors);
            throw new ApiException(422, 'VALIDATION', is_string($first) ? $first : __('api.validation_failed'), $errors);
        }

        return [
            'score' => (int) $score,
            'reason_type' => (string) $reasonType,
            'reason' => $reason,
            'project_id' => $project?->id,
            'project_name' => $project?->name,
            'project_name_en' => $project?->name_en,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'private' => ($input['private'] ?? false) === true,
        ];
    }

    /**
     * @param  array{team: Team|null, person: User|null}  $ctx
     */
    private function tell(Rating $rating, array $ctx, int $actorId): void
    {
        if ($rating->private) {
            return;
        }

        $data = [
            'score' => (string) $rating->score,
            'note' => mb_substr((string) $rating->reason, 0, 140),
        ];

        if ($ctx['team'] instanceof Team) {
            if ($ctx['team']->lead_id) {
                $teamData = array_merge($data, ['team_name' => $ctx['team']->name]);
                if ($ctx['team']->name_en !== null && $ctx['team']->name_en !== '') {
                    $teamData['team_name_en'] = $ctx['team']->name_en;
                }
                $this->notifications->notify([(int) $ctx['team']->lead_id], 'team_rated', [
                    'actor_id' => $actorId,
                    'team_id' => (int) $ctx['team']->id,
                    'data' => $teamData,
                ]);
            }
        } elseif ($ctx['person'] instanceof User) {
            $this->notifications->notify([(int) $ctx['person']->id], 'person_rated', [
                'actor_id' => $actorId,
                'data' => $data,
            ]);
        }
    }

    /**
     * @param  Collection<int, Rating>  $ratings
     * @return array<string, mixed>
     */
    private function summarize(Collection $ratings): array
    {
        $since = now('UTC')->subDays(self::LIMITS['trendDays']);
        $recent = $ratings->filter(fn (Rating $r) => $r->created_at !== null && $r->created_at->gte($since))->values();
        $before = $ratings->filter(fn (Rating $r) => $r->created_at === null || $r->created_at->lt($since))->values();

        $avgOf = function (Collection $list): array {
            $count = $list->count();

            return [
                'count' => $count,
                'average' => $count > 0 ? round($list->sum('score') / $count, 1) : null,
            ];
        };

        $byType = [];
        foreach (Rating::REASON_TYPES as $t) {
            $byType[$t] = $ratings->where('reason_type', $t)->count();
        }

        $byScore = [];
        foreach ([1, 2, 3, 4, 5] as $s) {
            $byScore[] = $ratings->where('score', $s)->count();
        }

        /** @var Rating|null $newest */
        $newest = $ratings->sortByDesc(fn (Rating $r) => ($r->created_at?->getTimestamp() ?? 0).'-'.$r->id)->first();

        $summary = array_merge($avgOf($ratings), [
            'recent' => $avgOf($recent),
            'before' => $avgOf($before),
            'by_type' => $byType,
            'by_score' => $byScore,
        ]);

        if ($newest !== null) {
            $summary['last'] = [
                'score' => (int) $newest->score,
                'reason_type' => (string) $newest->reason_type,
                'created_at' => BaghdadTime::iso($newest->created_at),
            ];
        }

        return $summary;
    }

    private function scoreText(int $score): string
    {
        $word = self::SCORE_WORDS_AR[$score] ?? '';

        return trim("{$score} {$word}");
    }

    /**
     * @return array{type: string, id: int, label: string}
     */
    private function auditTarget(Rating $rating, string $label): array
    {
        return [
            'type' => $rating->subject_type === 'team' ? 'team' : 'user',
            'id' => (int) $rating->subject_id,
            'label' => $label,
        ];
    }

    private function isDay(mixed $d): bool
    {
        if (! is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            return false;
        }
        [$y, $m, $day] = array_map('intval', explode('-', $d));

        return checkdate($m, $day, $y);
    }
}
