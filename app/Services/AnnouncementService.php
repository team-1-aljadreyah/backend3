<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use App\Support\BaghdadTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnnouncementService
{
    public const LIMITS = ['title' => 160, 'body' => 5000];

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return Collection<int, Announcement>
     */
    public function list(User $user): Collection
    {
        $activeIds = User::query()->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $audienceCount = count($activeIds);

        $announcements = Announcement::query()
            ->with(['reads' => fn ($q) => $q->whereIn('user_id', $activeIds)])
            ->orderByDesc('important')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($announcements as $a) {
            $a->seen = $a->reads->contains('user_id', $user->id);
            $a->seen_count = $a->reads->count();
            $a->audience_count = $audienceCount;
        }

        return $announcements;
    }

    public function show(User $user, int $id): Announcement
    {
        $a = Announcement::query()->find($id);
        if (! $a) {
            throw ApiException::notFound('api.announcements.not_found');
        }

        return $this->hydrateView($a, $user->id);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Announcement
    {
        $this->advanced->requireFeature($user, 'announcements');
        if (! $this->access->canPostAnnouncements($user)) {
            throw ApiException::forbidden('api.announcements.post_forbidden');
        }
        $this->advanced->requireAction($user, 'post_announcement');

        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($title === '') {
            throw ApiException::validation('title', 'api.announcements.title_required');
        }
        if (mb_strlen($title) > self::LIMITS['title']) {
            throw ApiException::validation('title', 'api.announcements.title_max');
        }
        if (mb_strlen($body) > self::LIMITS['body']) {
            throw ApiException::validation('body', 'api.announcements.body_max');
        }

        return DB::transaction(function () use ($user, $title, $body, $input) {
            $now = now('UTC');
            $a = Announcement::create([
                'title' => $title,
                'body' => $body,
                'important' => (bool) ($input['important'] ?? false),
                'author_id' => $user->id,
                'created_at' => $now,
            ]);

            AnnouncementRead::create([
                'announcement_id' => $a->id,
                'user_id' => $user->id,
                'at' => $now,
            ]);

            $activeIds = User::query()->where('active', true)->pluck('id')->all();
            $this->notifications->notify($activeIds, 'announcement', [
                'actor_id' => $user->id,
                'announcement_id' => $a->id,
                'data' => ['title' => $title],
            ]);

            return $this->hydrateView($a, $user->id);
        });
    }

    public function remove(User $user, int $id): void
    {
        $a = Announcement::query()->find($id);
        if (! $a) {
            throw ApiException::notFound('api.announcements.not_found');
        }
        if (! $this->access->canManageAnnouncement($user, $a)) {
            throw ApiException::forbidden('api.announcements.delete_forbidden');
        }

        DB::transaction(function () use ($user, $a) {
            $id = $a->id;
            $title = $a->title;
            $a->delete();
            $this->audit->log($user->id, 'announcement_deleted', [
                'type' => 'announcement',
                'id' => $id,
                'label' => $title,
            ]);
        });
    }

    public function markSeen(User $user, int $id): void
    {
        $a = Announcement::query()->find($id);
        if (! $a) {
            throw ApiException::notFound('api.announcements.not_found');
        }

        AnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $a->id, 'user_id' => $user->id],
            ['at' => now('UTC')]
        );
    }

    /**
     * @return array{seen: array<int, array{user_id: int, at: string}>, not_seen: int[]}
     */
    public function reads(User $user, int $id): array
    {
        $a = Announcement::query()->find($id);
        if (! $a) {
            throw ApiException::notFound('api.announcements.not_found');
        }
        if (! $this->access->canManageAnnouncement($user, $a)) {
            throw ApiException::forbidden('api.announcements.reads_forbidden');
        }

        $activeIds = User::query()->where('active', true)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $reads = AnnouncementRead::query()
            ->where('announcement_id', $a->id)
            ->whereIn('user_id', $activeIds)
            ->orderBy('at')
            ->orderBy('id')
            ->get();

        $seenIds = $reads->pluck('user_id')->map(fn ($x) => (int) $x)->all();

        return [
            'seen' => $reads->map(fn (AnnouncementRead $r) => [
                'user_id' => (int) $r->user_id,
                'at' => BaghdadTime::iso($r->at),
            ])->values()->all(),
            'not_seen' => array_values(array_diff($activeIds, $seenIds)),
        ];
    }

    private function hydrateView(Announcement $a, int $userId): Announcement
    {
        $activeIds = User::query()->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reads = AnnouncementRead::query()
            ->where('announcement_id', $a->id)
            ->whereIn('user_id', $activeIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $a->seen = in_array($userId, $reads, true);
        $a->seen_count = count($reads);
        $a->audience_count = count($activeIds);

        return $a;
    }
}
