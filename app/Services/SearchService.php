<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Announcement;
use App\Models\Bug;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SearchNormalizer;

class SearchService
{
    public const EACH_LIMIT = 8;

    public const MIN_LEN = 2;

    public const MAX_LEN = 80;

    public function __construct(private readonly AccessService $access) {}

    /**
     * @return array{q: string, hits: array<int, array<string, mixed>>}
     */
    public function search(User $user, ?string $raw): array
    {
        $q = trim((string) ($raw ?? ''));
        if (mb_strlen($q) < self::MIN_LEN) {
            throw ApiException::validation('q', 'api.search.q_min');
        }
        if (mb_strlen($q) > self::MAX_LEN) {
            throw ApiException::validation('q', 'api.search.q_max');
        }

        $visibleProjects = Project::query()
            ->with('members:id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Project $p) => $this->access->canSeeProject($user, $p))
            ->values();

        $projectOf = $visibleProjects->keyBy('id');
        $visibleIds = $visibleProjects->pluck('id')->all();

        $hits = [];

        $projectCount = 0;
        foreach ($visibleProjects as $p) {
            if (SearchNormalizer::matches($q, $p->name, $p->name_en, $p->code)) {
                $hits[] = ApiResponse::clean([
                    'kind' => 'project',
                    'id' => (int) $p->id,
                    'title' => $p->name,
                    'title_en' => $p->name_en,
                    'code' => $p->code,
                ]);
                if (++$projectCount >= self::EACH_LIMIT) {
                    break;
                }
            }
        }

        if ($visibleIds !== []) {
            $taskCount = 0;
            foreach (Task::query()->whereIn('project_id', $visibleIds)->orderBy('id')->get() as $t) {
                if (SearchNormalizer::matches($q, $t->title, $t->code)) {
                    $p = $projectOf->get($t->project_id);
                    $hits[] = ApiResponse::clean([
                        'kind' => 'task',
                        'id' => (int) $t->id,
                        'title' => $t->title,
                        'code' => $t->code,
                        'context' => $p?->name,
                        'context_en' => $p?->name_en,
                    ]);
                    if (++$taskCount >= self::EACH_LIMIT) {
                        break;
                    }
                }
            }

            $bugCount = 0;
            foreach (Bug::query()->whereIn('project_id', $visibleIds)->orderBy('id')->get() as $b) {
                if (SearchNormalizer::matches($q, $b->title, $b->code)) {
                    $p = $projectOf->get($b->project_id);
                    $hits[] = ApiResponse::clean([
                        'kind' => 'bug',
                        'id' => (int) $b->id,
                        'title' => $b->title,
                        'code' => $b->code,
                        'context' => $p?->name,
                        'context_en' => $p?->name_en,
                    ]);
                    if (++$bugCount >= self::EACH_LIMIT) {
                        break;
                    }
                }
            }
        }

        $annCount = 0;
        foreach (Announcement::query()->orderByDesc('important')->orderByDesc('id')->get() as $a) {
            if (SearchNormalizer::matches($q, $a->title, $a->body)) {
                $hits[] = [
                    'kind' => 'announcement',
                    'id' => (int) $a->id,
                    'title' => $a->title,
                ];
                if (++$annCount >= self::EACH_LIMIT) {
                    break;
                }
            }
        }

        $personCount = 0;
        foreach (User::query()->where('active', true)->orderBy('id')->get() as $u) {
            if (SearchNormalizer::matches($q, $u->name, $u->name_en, $u->email, $u->title)) {
                $hits[] = ApiResponse::clean([
                    'kind' => 'person',
                    'id' => (int) $u->id,
                    'title' => $u->name,
                    'title_en' => $u->name_en,
                    'context' => $u->title !== '' ? $u->title : null,
                    'role' => $u->role,
                    'team_id' => $u->team_id !== null ? (int) $u->team_id : null,
                ]);
                if (++$personCount >= self::EACH_LIMIT) {
                    break;
                }
            }
        }

        return [
            'q' => $q,
            'hits' => $hits,
        ];
    }
}
