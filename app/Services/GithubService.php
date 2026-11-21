<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Bug;
use App\Models\GithubItem;
use App\Models\GithubWebhookDelivery;
use App\Models\Project;
use App\Models\ProjectGithubSetting;
use App\Models\Task;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GithubService
{
    public const MAX_REPOS = 5;

    private readonly AdvancedSettingsService $advanced;

    public function __construct(
        private readonly AccessService $access,
        private readonly NotificationService $notifications,
        ?AdvancedSettingsService $advanced = null,
    ) {
        $this->advanced = $advanced ?? app(AdvancedSettingsService::class);
    }

    /**
     * @return array{project_id: int, enabled: bool, repos: string[]}
     */
    public function settings(User $user, int $projectId): array
    {
        $this->access->projectForOrFail($user, $projectId, ['members:id']);
        $row = ProjectGithubSetting::query()->where('project_id', $projectId)->first();

        return [
            'project_id' => $projectId,
            'enabled' => (bool) ($row?->enabled ?? false),
            'repos' => $row?->repos ?? [],
        ];
    }

    /**
     * @param  string[]  $reposInput
     * @return array{project_id: int, enabled: bool, repos: string[]}
     */
    public function saveSettings(User $user, int $projectId, bool $enabled, array $reposInput): array
    {
        $this->advanced->requireFeature($user, 'github');
        $project = $this->access->projectForOrFail($user, $projectId, ['members:id']);
        if (! $this->access->canManageProjectSetup($user, $project)) {
            throw ApiException::forbidden('api.github.manage_forbidden');
        }

        $repos = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r), $reposInput), fn ($r) => $r !== '')));
        if (count($repos) > self::MAX_REPOS) {
            throw ApiException::validation('repos', 'api.github.repos_max', ['max' => self::MAX_REPOS]);
        }

        foreach ($repos as $repo) {
            if (! $this->isRepoName($repo)) {
                throw ApiException::validation('repos', 'api.github.repo_invalid', ['repo' => $repo]);
            }
        }

        if ($enabled && $repos === []) {
            throw ApiException::validation('repos', 'api.github.repos_required_when_enabled');
        }

        $setting = ProjectGithubSetting::query()->updateOrCreate(
            ['project_id' => $projectId],
            ['enabled' => $enabled, 'repos' => $repos]
        );

        return [
            'project_id' => $projectId,
            'enabled' => (bool) $setting->enabled,
            'repos' => $setting->repos ?? [],
        ];
    }

    /**
     * @return array{enabled: bool, repos: string[], items: array<int, array<string, mixed>>}
     */
    public function links(User $user, string $type, int $id): array
    {
        $item = $type === 'task' ? Task::query()->find($id) : Bug::query()->find($id);
        if (! $item) {
            throw ApiException::notFound('api.github.not_found');
        }

        $this->access->projectForOrFail($user, (int) $item->project_id, ['members:id']);
        $setting = ProjectGithubSetting::query()->where('project_id', $item->project_id)->first();
        if (! $setting || ! $setting->enabled || (! $user->isCeo() && ! $this->advanced->featureIsOn('github'))) {
            return ['enabled' => false, 'repos' => [], 'items' => []];
        }

        $repos = $setting->repos ?? [];
        if ($repos === []) {
            return ['enabled' => false, 'repos' => [], 'items' => []];
        }

        $candidates = GithubItem::query()
            ->whereIn('repo', $repos)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->get();

        $matched = [];
        foreach ($candidates as $g) {
            $safeUrl = $this->safeGithubUrl($g->url);
            if ($safeUrl === null || ! $this->mentions($g->title, $item->code)) {
                continue;
            }
            $matched[] = ApiResponse::clean([
                'id' => $g->external_id,
                'kind' => $g->kind,
                'repo' => $g->repo,
                'ref' => $g->ref,
                'title' => $g->title,
                'state' => $g->state,
                'author' => $g->author,
                'at' => BaghdadTime::iso($g->at),
                'url' => $safeUrl,
            ]);
        }

        return [
            'enabled' => true,
            'repos' => $repos,
            'items' => $matched,
        ];
    }

    /**
     * Process verified GitHub App webhooks (pull_request and push).
     *
     * @param  array<string, mixed>  $payload
     * @return array{processed: bool, duplicate?: bool}
     */
    public function handleWebhook(string $event, string $deliveryId, string $rawBody, string $signatureHeader, array $payload): array
    {
        $secret = (string) config('services.github.webhook_secret', env('GITHUB_WEBHOOK_SECRET', ''));
        if ($secret === '' || ! $this->verifySignature($rawBody, $signatureHeader, $secret)) {
            throw ApiException::forbidden('api.github.webhook_unauthorized');
        }

        if ($deliveryId !== '' && GithubWebhookDelivery::query()->where('delivery_id', $deliveryId)->exists()) {
            return ['processed' => true, 'duplicate' => true];
        }

        $repo = (string) ($payload['repository']['full_name'] ?? '');
        $configuredProjects = ProjectGithubSetting::query()
            ->where('enabled', true)
            ->get()
            ->filter(fn (ProjectGithubSetting $s) => in_array($repo, $s->repos ?? [], true));

        return DB::transaction(function () use ($event, $deliveryId, $repo, $payload, $configuredProjects) {
            if ($deliveryId !== '') {
                GithubWebhookDelivery::create([
                    'delivery_id' => $deliveryId,
                    'event' => $event,
                    'received_at' => now('UTC'),
                ]);
            }

            if ($configuredProjects->isEmpty()) {
                return ['processed' => true];
            }

            $projectIds = $configuredProjects->pluck('project_id')->map(fn ($id) => (int) $id)->all();

            if ($event === 'pull_request' && isset($payload['pull_request'])) {
                $pr = $payload['pull_request'];
                $number = (int) ($pr['number'] ?? 0);
                $title = trim((string) ($pr['title'] ?? ''));
                $branch = trim((string) ($pr['head']['ref'] ?? ''));
                $merged = (bool) ($pr['merged'] ?? false);
                $rawState = (string) ($pr['state'] ?? 'open');
                $state = $merged ? 'merged' : ($rawState === 'closed' ? 'closed' : 'open');
                $url = $this->safeGithubUrl((string) ($pr['html_url'] ?? ''));
                $author = (string) ($pr['user']['login'] ?? 'github');
                $at = CarbonImmutable::parse((string) ($pr['updated_at'] ?? $pr['created_at'] ?? now('UTC')))->utc();
                $displayTitle = $branch !== '' && ! $this->mentions($title, $branch) && preg_match('/\b(?:TSK|BUG)-\d+\b/i', $branch) === 1
                    ? "{$title} ({$branch})"
                    : $title;

                if ($number > 0 && $url !== null) {
                    GithubItem::query()->updateOrCreate(
                        ['repo' => $repo, 'external_id' => "pr-{$number}"],
                        [
                            'kind' => 'pr',
                            'ref' => "#{$number}",
                            'title' => mb_substr($displayTitle, 0, 255),
                            'state' => $state,
                            'author' => mb_substr($author, 0, 120),
                            'at' => $at,
                            'url' => $url,
                            'created_at' => now('UTC'),
                        ]
                    );

                    if ($merged && ($payload['action'] ?? '') === 'closed') {
                        $this->notifyMergedPr($projectIds, $repo, $number, "{$title} {$branch}");
                    }
                }
            } elseif ($event === 'push' && isset($payload['commits']) && is_array($payload['commits'])) {
                foreach ($payload['commits'] as $commit) {
                    $sha = (string) ($commit['id'] ?? '');
                    $short = substr($sha, 0, 7);
                    $message = trim((string) ($commit['message'] ?? ''));
                    $firstLine = strtok($message, "\r\n") ?: $message;
                    $url = $this->safeGithubUrl((string) ($commit['url'] ?? "https://github.com/{$repo}/commit/{$sha}"));
                    $author = (string) ($commit['author']['username'] ?? $commit['author']['name'] ?? 'github');
                    $at = CarbonImmutable::parse((string) ($commit['timestamp'] ?? now('UTC')))->utc();

                    if ($short !== '' && $url !== null) {
                        GithubItem::query()->updateOrCreate(
                            ['repo' => $repo, 'external_id' => "c-{$short}"],
                            [
                                'kind' => 'commit',
                                'ref' => $short,
                                'title' => mb_substr($firstLine, 0, 255),
                                'state' => null,
                                'author' => mb_substr($author, 0, 120),
                                'at' => $at,
                                'url' => $url,
                                'created_at' => now('UTC'),
                            ]
                        );
                    }
                }
            }

            return ['processed' => true];
        });
    }

    public function isRepoName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}$/', $name) === 1
            && ! str_ends_with($name, '.git');
    }

    public function safeGithubUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($scheme === 'https' && $host === 'github.com') ? $url : null;
    }

    public function mentions(string $text, string $code): bool
    {
        $escaped = preg_quote($code, '/');

        return preg_match('/(^|[^A-Za-z0-9-])'.$escaped.'(?![0-9])/i', $text) === 1;
    }

    private function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @param  int[]  $projectIds
     */
    private function notifyMergedPr(array $projectIds, string $repo, int $prNumber, string $text): void
    {
        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('assignee_id')
            ->with('project')
            ->get();

        foreach ($tasks as $task) {
            if (! $this->mentions($text, $task->code)) {
                continue;
            }
            $this->notifications->notify([$task->assignee_id], 'github_pr_merged', [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'data' => array_merge($this->notifications->aboutTask($task, $task->project), [
                    'note' => "{$repo} #{$prNumber}",
                ]),
            ]);
        }
    }
}
