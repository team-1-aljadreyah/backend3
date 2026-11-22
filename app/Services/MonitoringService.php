<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Bug;
use App\Models\MonitorNote;
use App\Models\MonitorNoteShare;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonitoringService
{
    public const LIMITS = [
        'title' => 160,
        'body' => 5000,
        'reply' => 2000,
        'subject' => 120,
        'people' => 30,
    ];

    public const KINDS = ['report', 'feedback', 'problem', 'request'];

    public function __construct(
        private readonly AccessService $access,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
        private readonly PerformanceService $performance,
    ) {}

    /**
     * @return Collection<int, MonitorNote>
     */
    public function notes(User $user): Collection
    {
        $query = MonitorNote::query()->with('shares');
        if (! $user->isCeo()) {
            $query->where(function ($q) use ($user) {
                $q->where('author_id', $user->id);
                if ($user->role === 'monitor') {
                    $q->orWhere('kind', 'request');
                }
                $q->orWhereHas('shares', fn ($s) => $s->where('user_id', $user->id));
            });
        }

        return $query
            ->orderByRaw('COALESCE(sent_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): MonitorNote
    {
        $kind = (string) ($input['kind'] ?? '');
        $isRequest = $kind === 'request';

        if ($isRequest && ! $this->access->canSendRequests($user)) {
            throw ApiException::forbidden('api.monitoring.request_ceo_forbidden');
        }
        if ($isRequest) {
            $this->advanced->requireFeature($user, 'requests');
        }
        if (! $isRequest && ! $this->access->canWriteNotes($user)) {
            throw ApiException::forbidden('api.monitoring.write_forbidden');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw ApiException::validation('kind', 'api.monitoring.kind_invalid');
        }

        $subject = isset($input['subject']) ? trim((string) $input['subject']) : '';
        if ($isRequest && $subject === '') {
            throw ApiException::validation('subject', 'api.monitoring.subject_required');
        }
        if ($subject !== '' && mb_strlen($subject) > self::LIMITS['subject']) {
            throw ApiException::validation('subject', 'api.monitoring.subject_max');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw ApiException::validation('title', 'api.monitoring.title_required');
        }
        if (mb_strlen($title) > self::LIMITS['title']) {
            throw ApiException::validation('title', 'api.monitoring.title_max');
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            throw ApiException::validation('body', 'api.monitoring.body_required');
        }
        if (mb_strlen($body) > self::LIMITS['body']) {
            throw ApiException::validation('body', 'api.monitoring.body_max');
        }

        $rawAbout = is_array($input['about'] ?? null) ? $input['about'] : ['type' => 'general'];
        if ($kind === 'feedback') {
            if (($rawAbout['type'] ?? '') !== 'person') {
                throw ApiException::validation('about', 'api.monitoring.feedback_person_only');
            }
            if ((int) ($rawAbout['id'] ?? 0) === (int) $user->id) {
                throw ApiException::validation('about', 'api.monitoring.feedback_not_self');
            }
        }

        $rawPeopleIds = array_values(array_unique(array_map('intval', (array) ($input['people_ids'] ?? []))));
        if (count($rawPeopleIds) > self::LIMITS['people']) {
            throw ApiException::validation('people_ids', 'api.monitoring.people_max');
        }

        $people = [];
        if ($rawPeopleIds !== []) {
            $usersById = User::query()
                ->whereIn('id', $rawPeopleIds)
                ->where('active', true)
                ->get()
                ->keyBy('id');
            foreach ($rawPeopleIds as $pid) {
                $pUser = $usersById->get($pid);
                if (! $pUser) {
                    throw ApiException::validation('people_ids', 'api.monitoring.people_invalid');
                }
                $entry = ['id' => (int) $pUser->id, 'label' => $pUser->name];
                if ($pUser->name_en !== null && $pUser->name_en !== '') {
                    $entry['label_en'] = $pUser->name_en;
                }
                $people[] = $entry;
            }
        }

        if (isset($input['period']) && $input['period'] !== null && $kind !== 'report') {
            throw ApiException::validation('period', 'api.monitoring.period_report_only');
        }

        $about = $this->resolveAbout($isRequest ? ['type' => 'general'] : $rawAbout);
        $facts = ! empty($input['period']) && is_array($input['period'])
            ? $this->buildFacts($about, $input['period'])
            : null;

        $shareIds = [];
        if (! $isRequest && ! empty($input['share_ids'])) {
            $shareIds = $this->validateShareIds((array) $input['share_ids']);
        }

        $activeMonitorRecipients = $isRequest
            ? User::query()
                ->where('active', true)
                ->where('role', 'monitor')
                ->where('id', '!=', $user->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $straightToCeo = ! $isRequest
            ? ! empty($input['send'])
            : ($user->role === 'monitor' || $activeMonitorRecipients === []);

        $triage = ($isRequest && ! $straightToCeo) ? 'pending' : null;

        return DB::transaction(function () use ($user, $kind, $title, $body, $about, $input, $subject, $people, $facts, $straightToCeo, $triage, $activeMonitorRecipients, $shareIds) {
            $now = now('UTC');
            $note = MonitorNote::create([
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'about_type' => $about['type'],
                'about_id' => $about['id'] ?? null,
                'about_label' => $about['label'] ?? null,
                'about_label_en' => $about['label_en'] ?? null,
                'tone' => $kind === 'feedback' ? (($input['tone'] ?? '') === 'praise' ? 'praise' : 'improve') : null,
                'subject' => $subject !== '' ? $subject : null,
                'people' => $people !== [] ? $people : null,
                'author_id' => $user->id,
                'created_at' => $now,
                'sent_at' => $straightToCeo ? $now : null,
                'ceo_state' => $straightToCeo ? 'new' : null,
                'facts' => $facts,
                'triage' => $triage,
            ]);

            if ($straightToCeo) {
                $this->notifyCeos($note->title, (int) $user->id);
            } elseif ($triage === 'pending' && $activeMonitorRecipients !== []) {
                $this->notifications->notify($activeMonitorRecipients, 'note_sent', [
                    'actor_id' => $user->id,
                    'data' => [
                        'title' => $note->title,
                        'queue' => 'monitor',
                    ],
                ]);
            }

            if ($shareIds !== []) {
                $this->applyShares($note, $shareIds, (int) $user->id);
            }

            return $note->fresh(['shares']);
        });
    }

    public function send(User $user, int $id): MonitorNote
    {
        $note = $this->findForUserOrFail($user, $id);
        if (! $this->access->canSendToCeo($user, $note)) {
            throw ApiException::forbidden('api.monitoring.send_forbidden');
        }

        return DB::transaction(function () use ($user, $note) {
            $note->sent_at = now('UTC');
            $note->ceo_state = 'new';
            $note->save();

            $this->notifyCeos($note->title, (int) $user->id);

            return $note->fresh(['shares']);
        });
    }

    public function forwardRequest(User $user, int $id, ?string $rawNote): MonitorNote
    {
        if (! $this->access->canTriageRequests($user)) {
            throw ApiException::forbidden('api.monitoring.request_triage_forbidden');
        }

        $note = $this->findWaitingRequestOrFail($user, $id);
        $text = $rawNote !== null ? trim($rawNote) : '';
        if ($text !== '' && mb_strlen($text) > self::LIMITS['reply']) {
            throw ApiException::validation('note', 'api.monitoring.forward_note_max');
        }

        return DB::transaction(function () use ($user, $note, $text) {
            $now = now('UTC');
            $note->triage = 'forwarded';
            $note->triaged_by = $user->id;
            $note->triaged_at = $now;
            if ($text !== '') {
                $note->forward_note = $text;
            }
            $note->sent_at = $now;
            $note->ceo_state = 'new';
            $note->save();

            $this->notifyCeos($note->title, (int) $user->id);

            return $note->fresh(['shares']);
        });
    }

    public function answerRequest(User $user, int $id, string $rawReply): MonitorNote
    {
        if (! $this->access->canTriageRequests($user)) {
            throw ApiException::forbidden('api.monitoring.request_triage_forbidden');
        }

        $note = $this->findWaitingRequestOrFail($user, $id);
        $reply = trim($rawReply);
        if ($reply === '') {
            throw ApiException::validation('reply', 'api.monitoring.reply_required');
        }
        if (mb_strlen($reply) > self::LIMITS['reply']) {
            throw ApiException::validation('reply', 'api.monitoring.reply_max');
        }

        return DB::transaction(function () use ($user, $note, $reply) {
            $note->triage = 'answered';
            $note->triaged_by = $user->id;
            $note->triaged_at = now('UTC');
            $note->monitor_reply = $reply;
            $note->save();

            $this->notifications->notify([(int) $note->author_id], 'note_answered', [
                'actor_id' => $user->id,
                'data' => [
                    'title' => $note->title,
                    'note' => $reply,
                    'note_kind' => 'request',
                ],
            ]);

            return $note->fresh(['shares']);
        });
    }

    /**
     * @param  array<int, mixed>  $userIds
     */
    public function share(User $user, int $id, array $userIds): MonitorNote
    {
        $note = $this->findForUserOrFail($user, $id);
        if (! $this->access->canShareNote($user, $note)) {
            throw ApiException::forbidden('api.monitoring.share_forbidden');
        }

        if ($userIds === []) {
            throw ApiException::validation('share_ids', 'api.monitoring.share_min');
        }

        $validatedIds = $this->validateShareIds($userIds);

        return DB::transaction(function () use ($note, $validatedIds, $user) {
            $this->applyShares($note, $validatedIds, (int) $user->id);

            return $note->fresh(['shares']);
        });
    }

    /**
     * @return Collection<int, MonitorNote>
     */
    public function received(User $user): Collection
    {
        return MonitorNote::query()
            ->with('shares')
            ->whereHas('shares', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function markSeen(User $user, int $id): MonitorNote
    {
        $note = $this->findForUserOrFail($user, $id);
        $share = MonitorNoteShare::query()
            ->where('note_id', $note->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $share) {
            throw ApiException::notFound('api.monitoring.not_found');
        }

        if ($share->seen_at === null) {
            $share->seen_at = now('UTC');
            $share->save();
        }

        return $note->fresh(['shares']);
    }

    /**
     * @return Collection<int, MonitorNote>
     */
    public function inbox(User $user): Collection
    {
        if (! $this->access->canUseCeoInbox($user)) {
            throw ApiException::forbidden('api.monitoring.inbox_forbidden');
        }

        return MonitorNote::query()
            ->with('shares')
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    public function answer(User $user, int $id, array $answer): MonitorNote
    {
        if (! $this->access->canUseCeoInbox($user)) {
            throw ApiException::forbidden('api.monitoring.inbox_forbidden');
        }

        $note = $this->findForUserOrFail($user, $id);
        if ($note->sent_at === null) {
            throw ApiException::notFound('api.monitoring.not_found');
        }

        $reply = isset($answer['reply']) ? trim((string) $answer['reply']) : '';
        if ($reply !== '' && mb_strlen($reply) > self::LIMITS['reply']) {
            throw ApiException::validation('reply', 'api.monitoring.reply_max');
        }

        $state = (string) ($answer['state'] ?? '');
        if (! in_array($state, ['seen', 'done'], true)) {
            throw ApiException::validation('state', 'api.monitoring.state_invalid');
        }

        $reopen = ! empty($answer['reopen']);

        return DB::transaction(function () use ($user, $note, $reply, $state, $reopen) {
            if ($reopen) {
                $note->ceo_state = 'seen';
            } elseif ($note->ceo_state === 'done' && $state === 'seen' && $reply === '') {
                $note->ceo_state = 'done';
            } else {
                $note->ceo_state = $state;
            }

            if ($reply !== '') {
                $note->ceo_reply = $reply;
                $note->replied_at = now('UTC');
                $this->notifications->notify([(int) $note->author_id], 'note_answered', [
                    'actor_id' => $user->id,
                    'data' => [
                        'title' => $note->title,
                        'note' => $reply,
                        'note_kind' => $note->kind,
                    ],
                ]);
            }

            $note->save();

            return $note->fresh(['shares']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reportFacts(User $user, string $type, int $id, ?string $from, ?string $to): array
    {
        if (! $this->access->canWriteNotes($user)) {
            throw ApiException::forbidden('api.monitoring.reports_forbidden');
        }

        if (! in_array($type, ['team', 'person', 'project'], true)) {
            throw ApiException::validation('about', 'api.monitoring.about_not_general');
        }

        $about = $this->resolveAbout(['type' => $type, 'id' => $id]);

        return $this->buildFacts($about, ['from' => $from, 'to' => $to]);
    }

    private function findForUserOrFail(User $user, int $id): MonitorNote
    {
        $note = MonitorNote::query()->with('shares')->find($id);
        if (! $note || ! $this->access->canSeeNote($user, $note)) {
            throw ApiException::notFound('api.monitoring.not_found');
        }

        return $note;
    }

    private function findWaitingRequestOrFail(User $user, int $id): MonitorNote
    {
        $note = MonitorNote::query()->with('shares')->find($id);
        if (! $note || ! $this->access->canSeeNote($user, $note) || $note->kind !== 'request') {
            throw ApiException::notFound('api.monitoring.request_not_found');
        }
        if ($note->triage !== 'pending') {
            throw ApiException::badState('api.monitoring.request_already_handled');
        }

        return $note;
    }

    /**
     * @param  array<int, mixed>  $rawIds
     * @return int[]
     */
    private function validateShareIds(array $rawIds): array
    {
        $unique = array_values(array_unique(array_map('intval', $rawIds)));
        if (count($unique) > self::LIMITS['people']) {
            throw ApiException::validation('share_ids', 'api.monitoring.people_max');
        }
        if ($unique !== []) {
            $activeCount = User::query()->whereIn('id', $unique)->where('active', true)->count();
            if ($activeCount !== count($unique)) {
                throw ApiException::validation('share_ids', 'api.monitoring.people_invalid');
            }
        }

        return $unique;
    }

    /**
     * @param  int[]  $ids
     */
    private function applyShares(MonitorNote $note, array $ids, int $actorId): void
    {
        $users = User::query()->whereIn('id', $ids)->where('active', true)->get()->keyBy('id');
        $already = MonitorNoteShare::query()->where('note_id', $note->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $now = now('UTC');
        $added = [];

        foreach ($ids as $id) {
            $targetUser = $users->get($id);
            if (! $targetUser || (int) $targetUser->id === (int) $note->author_id || $targetUser->role === 'ceo' || in_array((int) $targetUser->id, $already, true)) {
                continue;
            }
            MonitorNoteShare::create([
                'note_id' => $note->id,
                'user_id' => $targetUser->id,
                'label' => $targetUser->name,
                'label_en' => $targetUser->name_en,
                'shared_at' => $now,
            ]);
            $added[] = (int) $targetUser->id;
            $already[] = (int) $targetUser->id;
        }

        if ($added !== []) {
            $this->notifications->notify($added, 'note_sent', [
                'actor_id' => $actorId,
                'data' => [
                    'title' => $note->title,
                    'queue' => 'received',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $rawAbout
     * @return array{type: string, id?: int, label?: string, label_en?: string|null}
     */
    private function resolveAbout(array $rawAbout): array
    {
        $type = (string) ($rawAbout['type'] ?? '');
        if ($type === 'general') {
            return ['type' => 'general'];
        }

        $id = isset($rawAbout['id']) ? (int) $rawAbout['id'] : 0;
        $record = match ($type) {
            'team' => Team::query()->find($id),
            'project' => Project::query()->find($id),
            'person' => User::query()->where('id', $id)->where('active', true)->first(),
            default => null,
        };

        if (! $record) {
            throw ApiException::validation('about', 'api.monitoring.about_invalid');
        }

        return ApiResponse::clean([
            'type' => $type,
            'id' => (int) $record->id,
            'label' => $record->name,
            'label_en' => $record->name_en,
        ]);
    }

    /**
     * @param  array{type: string, id?: int, label?: string, label_en?: string|null}  $about
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    private function buildFacts(array $about, array $period): array
    {
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;
        if (! $this->isDay($from) || ! $this->isDay($to)) {
            throw ApiException::validation('period', 'api.monitoring.period_invalid');
        }
        if ($from > $to) {
            throw ApiException::validation('period', 'api.monitoring.period_order');
        }
        if ($about['type'] === 'general') {
            throw ApiException::validation('about', 'api.monitoring.about_not_general');
        }

        $today = BaghdadTime::today();
        $validPeriod = ['from' => (string) $from, 'to' => (string) $to];

        $allProjects = Project::query()->with('statuses')->orderBy('id')->get();
        $scope = $about['type'] === 'project'
            ? $allProjects->where('id', $about['id'])->values()
            : $allProjects;

        $scopeIds = $scope->pluck('id')->all();
        $tasks = $scopeIds !== []
            ? Task::query()->whereIn('project_id', $scopeIds)->with('currentApproval')->get()
            : collect();
        $bugs = $scopeIds !== []
            ? Bug::query()->whereIn('project_id', $scopeIds)->get()
            : collect();

        $activeUsers = User::query()->where('active', true)->orderBy('id')->get();
        if ($about['type'] === 'team') {
            $people = $activeUsers->where('team_id', $about['id'])->values();
        } elseif ($about['type'] === 'person') {
            $people = $activeUsers->where('id', $about['id'])->values();
        } else {
            $assigneeIds = $tasks->pluck('assignee_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
            $people = $activeUsers->whereIn('id', $assigneeIds)->values();
        }

        $peopleIds = $people->pluck('id')->map(fn ($v) => (int) $v)->all();

        $rows = [];
        if ($about['type'] === 'person') {
            foreach ($scope as $project) {
                $hasTasks = $tasks->contains(fn (Task $t) => (int) $t->project_id === (int) $project->id && (int) $t->assignee_id === (int) $about['id']);
                if (! $hasTasks) {
                    continue;
                }
                $perf = $this->performance->computePersonPerf((int) $about['id'], null, $tasks, collect([$project]), $validPeriod, $today);
                if ($perf['assigned'] <= 0) {
                    continue;
                }
                $rows[] = ApiResponse::clean([
                    'type' => 'project',
                    'id' => (int) $project->id,
                    'label' => $project->name,
                    'label_en' => $project->name_en,
                    'assigned' => $perf['assigned'],
                    'done' => $perf['done'],
                    'late' => $perf['late'],
                    'blocked' => $perf['blocked'],
                    'completion' => $perf['completion'],
                ]);
            }
        } else {
            foreach ($people as $u) {
                $perf = $this->performance->computePersonPerf((int) $u->id, $u->team_id, $tasks, $scope, $validPeriod, $today);
                $rows[] = ApiResponse::clean([
                    'type' => 'person',
                    'id' => (int) $u->id,
                    'label' => $u->name,
                    'label_en' => $u->name_en,
                    'assigned' => $perf['assigned'],
                    'done' => $perf['done'],
                    'late' => $perf['late'],
                    'blocked' => $perf['blocked'],
                    'completion' => $perf['completion'],
                ]);
            }
        }

        $assigned = array_sum(array_column($rows, 'assigned'));
        $done = array_sum(array_column($rows, 'done'));
        $late = array_sum(array_column($rows, 'late'));
        $blocked = array_sum(array_column($rows, 'blocked'));

        $projectOf = $scope->keyBy('id');
        $withDue = 0;
        $onTime = 0;
        $involvedProjectIds = [];
        foreach ($tasks as $t) {
            $project = $projectOf->get($t->project_id);
            if (! $project || $t->assignee_id === null || ! in_array((int) $t->assignee_id, $peopleIds, true)) {
                continue;
            }
            $involvedProjectIds[(int) $project->id] = true;
            $finished = $this->performance->taskFinishedDay($project, $t);
            if ($t->due !== null && $finished !== null && $finished >= $validPeriod['from'] && $finished <= $validPeriod['to']) {
                $withDue++;
                if ($finished <= $t->due) {
                    $onTime++;
                }
            }
        }

        $planProjects = $scope->filter(fn (Project $p) => isset($involvedProjectIds[(int) $p->id]));
        $planned = $planProjects->isNotEmpty()
            ? $planProjects->sum(fn (Project $p) => BaghdadTime::plannedRatio($p->start, $p->end, $validPeriod['to'])) / $planProjects->count()
            : null;

        $scopedBugs = $bugs->filter(function (Bug $b) use ($about, $peopleIds) {
            if ($about['type'] === 'project') {
                return true;
            }

            return $b->assignee_id !== null && in_array((int) $b->assignee_id, $peopleIds, true);
        });

        $inPeriod = fn ($dt): bool => $dt !== null
            && ($d = BaghdadTime::toBaghdadDate($dt)) >= $validPeriod['from']
            && $d <= $validPeriod['to'];

        return [
            'about' => $about,
            'period' => $validPeriod,
            'assigned' => $assigned,
            'done' => $done,
            'left' => $assigned - $done,
            'late' => $late,
            'blocked' => $blocked,
            'completion' => $assigned > 0 ? $done / $assigned : 0,
            'on_time' => $withDue > 0 ? $onTime / $withDue : null,
            'planned' => $planned,
            'bugs_reported' => $scopedBugs->filter(fn (Bug $b) => $inPeriod($b->created_at))->count(),
            'bugs_closed' => $scopedBugs->filter(fn (Bug $b) => $inPeriod($b->closed_at))->count(),
            'bugs_open' => $scopedBugs->filter(fn (Bug $b) => $b->status !== 'closed')->count(),
            'rows' => array_values($rows),
            'counted_at' => BaghdadTime::iso(now('UTC')),
        ];
    }

    private function notifyCeos(string $title, int $actorId): void
    {
        $ceoIds = User::query()
            ->where('active', true)
            ->where('role', 'ceo')
            ->pluck('id')
            ->all();

        $this->notifications->notify($ceoIds, 'note_sent', [
            'actor_id' => $actorId,
            'data' => ['title' => $title],
        ]);
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
