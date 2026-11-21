<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\MessageResource;
use App\Models\Attachment;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatRead;
use App\Models\DirectConversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatService
{
    public const LIMITS = ['body' => 4000, 'groupName' => 60, 'groupOthers' => 2];

    public function __construct(
        private readonly AccessService $access,
        private readonly NotificationService $notifications,
        private readonly AdvancedSettingsService $advanced,
    ) {}

    /**
     * @return array{kind: string, id: int}|null
     */
    public function parseKey(string $key): ?array
    {
        if (preg_match('/^([ptdg])-(\d+)$/', $key, $m) !== 1) {
            return null;
        }

        $kind = match ($m[1]) {
            'p' => 'project',
            't' => 'team',
            'd' => 'direct',
            'g' => 'group',
        };

        return ['kind' => $kind, 'id' => (int) $m[2]];
    }

    /**
     * @return int[]|null
     */
    public function chatMembers(string $key): ?array
    {
        $parsed = $this->parseKey($key);
        if (! $parsed) {
            return null;
        }

        if ($parsed['kind'] === 'direct') {
            $direct = DirectConversation::query()->find($parsed['id']);

            return $direct ? $direct->memberIds() : null;
        }

        if ($parsed['kind'] === 'group') {
            $group = ChatGroup::query()->with('members')->find($parsed['id']);

            return $group ? $group->memberIds() : null;
        }

        $followerIds = User::query()
            ->where('active', true)
            ->whereIn('role', ['ceo', 'monitor'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($parsed['kind'] === 'project') {
            $project = Project::query()->with('members:id')->find($parsed['id']);
            if (! $project) {
                return null;
            }

            return array_values(array_unique(array_merge(
                [(int) $project->pm_id],
                $project->memberIds(),
                $followerIds
            )));
        }

        $teamExists = Team::query()->where('id', $parsed['id'])->exists();
        if (! $teamExists) {
            return null;
        }

        $teamMemberIds = User::query()
            ->where('active', true)
            ->where('team_id', $parsed['id'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($teamMemberIds, $followerIds)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversations(User $user, Request $request): array
    {
        $this->advanced->requireFeature($user, 'chat');

        $keys = [];
        foreach (Project::query()->pluck('id') as $pid) {
            $keys[] = "p-{$pid}";
        }
        foreach (Team::query()->pluck('id') as $tid) {
            $keys[] = "t-{$tid}";
        }
        foreach (DirectConversation::query()->where('user_low_id', $user->id)->orWhere('user_high_id', $user->id)->pluck('id') as $did) {
            $keys[] = "d-{$did}";
        }
        $groupIds = ChatGroupMember::query()->where('user_id', $user->id)->pluck('group_id')->all();
        foreach ($groupIds as $gid) {
            $keys[] = "g-{$gid}";
        }

        $conversations = [];
        foreach ($keys as $key) {
            $members = $this->chatMembers($key);
            if ($members === null || ! in_array((int) $user->id, $members, true)) {
                continue;
            }
            $conversations[] = $this->buildConversation($key, $user->id, $members, $request);
        }

        usort($conversations, function (array $a, array $b) {
            $aAt = $a['last_message']['created_at'] ?? '';
            $bAt = $b['last_message']['created_at'] ?? '';

            return strcmp($bAt, $aAt);
        });

        return $conversations;
    }

    /**
     * @return Collection<int, Message>
     */
    public function messages(User $user, string $key): Collection
    {
        $this->membersForOrFail($user, $key);

        $messages = Message::query()
            ->where('chat_key', $key)
            ->orderBy('id')
            ->get();

        return $this->hydrateFiles($messages);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function send(User $user, string $key, array $input): Message
    {
        $this->advanced->requireFeature($user, 'chat');
        $members = $this->membersForOrFail($user, $key);

        if (str_starts_with($key, 'd-')) {
            $otherId = null;
            foreach ($members as $mid) {
                if ((int) $mid !== (int) $user->id) {
                    $otherId = (int) $mid;
                    break;
                }
            }
            $other = $otherId !== null ? User::query()->find($otherId) : null;
            if ($other) {
                $otherHasWritten = Message::query()
                    ->where('chat_key', $key)
                    ->where('author_id', $other->id)
                    ->exists();
                if (! $this->access->canWriteInDirect($user, $other, $otherHasWritten)) {
                    throw ApiException::forbidden('api.chat.direct_ceo_forbidden');
                }
            }
        }

        $body = trim((string) ($input['body'] ?? ''));
        if (mb_strlen($body) > self::LIMITS['body']) {
            throw ApiException::validation('body', 'api.chat.body_max');
        }

        $replyTo = $input['reply_to'] ?? null;
        if ($replyTo !== null) {
            $replyExists = Message::query()
                ->where('id', (int) $replyTo)
                ->where('chat_key', $key)
                ->exists();
            if (! $replyExists) {
                throw ApiException::validation('reply_to', 'api.chat.reply_invalid');
            }
        }

        $rawMentions = array_values(array_unique(array_map('intval', (array) ($input['mentions'] ?? []))));
        $mentions = array_values(array_filter(
            $rawMentions,
            fn (int $id) => $id !== (int) $user->id && in_array($id, $members, true)
        ));

        return DB::transaction(function () use ($user, $key, $body, $replyTo, $mentions) {
            $now = now('UTC');
            $message = Message::create([
                'chat_key' => $key,
                'author_id' => $user->id,
                'body' => $body,
                'reply_to_id' => $replyTo !== null ? (int) $replyTo : null,
                'mentions' => $mentions,
                'created_at' => $now,
            ]);

            ChatRead::query()->updateOrCreate(
                ['chat_key' => $key, 'user_id' => $user->id],
                ['last_message_id' => $message->id, 'updated_at' => $now]
            );

            if ($mentions !== []) {
                $this->notifications->notify($mentions, 'chat_mention', [
                    'actor_id' => $user->id,
                    'chat_key' => $key,
                    'data' => ['note' => mb_substr($body, 0, 120)],
                ]);
            }

            $message->files = collect();

            return $message;
        });
    }

    public function markRead(User $user, string $key): void
    {
        $this->membersForOrFail($user, $key);
        $lastId = (int) (Message::query()->where('chat_key', $key)->max('id') ?? 0);

        $read = ChatRead::query()
            ->where('chat_key', $key)
            ->where('user_id', $user->id)
            ->first();

        if (! $read || (int) $read->last_message_id < $lastId) {
            ChatRead::query()->updateOrCreate(
                ['chat_key' => $key, 'user_id' => $user->id],
                ['last_message_id' => $lastId, 'updated_at' => now('UTC')]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function startDirect(User $user, int $otherUserId, Request $request): array
    {
        $this->advanced->requireFeature($user, 'chat');

        $other = User::query()->where('id', $otherUserId)->where('active', true)->first();
        if (! $other || (int) $other->id === (int) $user->id) {
            throw ApiException::validation('user_id', 'api.chat.direct_invalid_user');
        }

        if (! $this->access->canStartDirect($user, $other)) {
            throw ApiException::forbidden('api.chat.direct_ceo_forbidden');
        }

        $low = min((int) $user->id, (int) $other->id);
        $high = max((int) $user->id, (int) $other->id);

        $direct = DirectConversation::query()->firstOrCreate(
            ['user_low_id' => $low, 'user_high_id' => $high],
            ['created_at' => now('UTC')]
        );

        $key = "d-{$direct->id}";

        return $this->buildConversation($key, $user->id, $direct->memberIds(), $request);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createGroup(User $user, array $input, Request $request): array
    {
        $this->advanced->requireFeature($user, 'chat');
        $this->advanced->requireFeature($user, 'group_chats');

        if (! $this->access->canCreateGroupChat($user)) {
            throw ApiException::forbidden('api.chat.group_create_forbidden');
        }
        $this->advanced->requireAction($user, 'create_group_chat');

        $name = $this->checkGroupName($input['name'] ?? '');
        $others = $this->checkGroupMembers((array) ($input['member_ids'] ?? []), (int) $user->id);

        $group = DB::transaction(function () use ($user, $name, $others) {
            $g = ChatGroup::create([
                'name' => $name,
                'created_by' => $user->id,
                'created_at' => now('UTC'),
            ]);

            $this->syncGroupMembers($g->id, (int) $user->id, $others);

            return $g;
        });

        $key = "g-{$group->id}";

        return $this->buildConversation($key, $user->id, array_merge([(int) $user->id], $others), $request);
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public function updateGroup(User $user, string $key, array $change, Request $request): array
    {
        $group = $this->groupForOrFail($user, $key);

        if (! $user->isCeo() && (int) $group->created_by !== (int) $user->id) {
            throw ApiException::forbidden('api.chat.group_manage_forbidden');
        }

        $nextName = array_key_exists('name', $change)
            ? $this->checkGroupName($change['name'] ?? '')
            : $group->name;

        $others = array_key_exists('member_ids', $change)
            ? $this->checkGroupMembers((array) ($change['member_ids'] ?? []), (int) $group->created_by)
            : null;

        DB::transaction(function () use ($group, $nextName, $others) {
            if ($nextName !== $group->name) {
                $group->name = $nextName;
                $group->save();
            }

            if ($others !== null) {
                $this->syncGroupMembers($group->id, (int) $group->created_by, $others);
            }
        });

        $members = $this->chatMembers($key) ?? [];

        return $this->buildConversation($key, $user->id, $members, $request);
    }

    public function leaveGroup(User $user, string $key): void
    {
        $group = $this->groupForOrFail($user, $key);

        DB::transaction(function () use ($user, $key, $group) {
            ChatGroupMember::query()
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->delete();

            ChatRead::query()
                ->where('chat_key', $key)
                ->where('user_id', $user->id)
                ->delete();

            if ((int) $group->created_by === (int) $user->id) {
                $nextOwner = ChatGroupMember::query()
                    ->where('group_id', $group->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->first();
                if ($nextOwner) {
                    $group->created_by = (int) $nextOwner->user_id;
                    $group->save();
                }
            }
        });
    }

    private function groupForOrFail(User $user, string $key): ChatGroup
    {
        $this->membersForOrFail($user, $key);
        $parsed = $this->parseKey($key);
        $group = ($parsed && $parsed['kind'] === 'group') ? ChatGroup::query()->find($parsed['id']) : null;
        if (! $group) {
            throw ApiException::notFound('api.chat.group_not_found');
        }

        return $group;
    }

    private function checkGroupName(mixed $raw): string
    {
        $name = trim((string) $raw);
        if ($name === '') {
            throw ApiException::validation('name', 'api.chat.group_name_required');
        }
        if (mb_strlen($name) > self::LIMITS['groupName']) {
            throw ApiException::validation('name', 'api.chat.group_name_max', ['max' => self::LIMITS['groupName']]);
        }

        return $name;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return int[]
     */
    private function checkGroupMembers(array $ids, int $ownerId): array
    {
        $others = array_values(array_filter(
            array_unique(array_map('intval', $ids)),
            fn (int $id) => $id !== $ownerId && $id > 0
        ));

        if ($others !== []) {
            $validCount = User::query()
                ->whereIn('id', $others)
                ->where('active', true)
                ->where('role', '!=', 'admin')
                ->count();
            if ($validCount !== count($others)) {
                throw ApiException::validation('member_ids', 'api.chat.group_members_invalid');
            }
        }

        if (count($others) < self::LIMITS['groupOthers']) {
            throw ApiException::validation('member_ids', 'api.chat.group_members_min');
        }

        return $others;
    }

    /**
     * @param  int[]  $others
     */
    private function syncGroupMembers(int $groupId, int $ownerId, array $others): void
    {
        ChatGroupMember::query()->where('group_id', $groupId)->delete();
        $rows = [
            ['group_id' => $groupId, 'user_id' => $ownerId, 'position' => 0],
        ];
        foreach ($others as $idx => $memberId) {
            $rows[] = ['group_id' => $groupId, 'user_id' => $memberId, 'position' => $idx + 1];
        }
        ChatGroupMember::query()->insert($rows);
    }

    /**
     * @return int[]
     */
    private function membersForOrFail(User $user, string $key): array
    {
        $members = $this->chatMembers($key);
        if ($members === null || ! in_array((int) $user->id, $members, true)) {
            throw ApiException::notFound('api.chat.not_found');
        }

        return $members;
    }

    /**
     * @param  int[]  $members
     * @return array<string, mixed>
     */
    private function buildConversation(string $key, int $userId, array $members, Request $request): array
    {
        $parsed = $this->parseKey($key);
        $title = '';
        $titleEn = null;
        $createdBy = null;

        if ($parsed['kind'] === 'project') {
            $p = Project::query()->find($parsed['id']);
            $title = $p?->name ?? '';
            $titleEn = $p?->name_en;
        } elseif ($parsed['kind'] === 'team') {
            $t = Team::query()->find($parsed['id']);
            $title = $t?->name ?? '';
            $titleEn = $t?->name_en;
        } elseif ($parsed['kind'] === 'group') {
            $g = ChatGroup::query()->find($parsed['id']);
            $title = $g?->name ?? '';
            $createdBy = $g ? (int) $g->created_by : null;
        }

        $lastRead = (int) (ChatRead::query()
            ->where('chat_key', $key)
            ->where('user_id', $userId)
            ->value('last_message_id') ?? 0);

        $lastMessage = Message::query()->where('chat_key', $key)->orderByDesc('id')->first();
        if ($lastMessage) {
            $this->hydrateFiles(collect([$lastMessage]));
        }

        $unread = Message::query()
            ->where('chat_key', $key)
            ->where('id', '>', $lastRead)
            ->where('author_id', '!=', $userId)
            ->count();

        $out = [
            'key' => $key,
            'kind' => $parsed['kind'],
            'title' => $title,
            'member_ids' => $members,
            'unread' => $unread,
        ];

        if ($createdBy !== null) {
            $out['created_by'] = $createdBy;
        }
        if ($titleEn !== null && $titleEn !== '') {
            $out['title_en'] = $titleEn;
        }
        if ($lastMessage) {
            $out['last_message'] = (new MessageResource($lastMessage))->toArray($request);
        }

        return $out;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    private function hydrateFiles(Collection $messages): Collection
    {
        if ($messages->isEmpty()) {
            return $messages;
        }

        $ids = $messages->pluck('id')->all();
        $attachments = Attachment::query()
            ->where('owner_type', 'message')
            ->whereIn('owner_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('owner_id');

        foreach ($messages as $m) {
            $m->files = $attachments->get($m->id, collect());
        }

        return $messages;
    }
}
