<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Announcement;
use App\Models\Bug;
use App\Models\MonitorNote;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class AccessService
{
    public function isCeo(User $user): bool
    {
        return $user->isCeo();
    }

    public function isMember(User $user, Project $project): bool
    {
        if ((int) $project->pm_id === (int) $user->id) {
            return true;
        }

        if ($project->relationLoaded('members')) {
            return $project->members->contains('id', $user->id);
        }

        return $project->members()->where('users.id', $user->id)->exists();
    }

    public function canSeeProject(User $user, Project $project): bool
    {
        if ($this->isCeo($user) || in_array($user->role, ['monitor', 'admin'], true)) {
            return true;
        }

        return $this->isMember($user, $project);
    }

    public function projectForOrFail(User $user, int $projectId, array $with = []): Project
    {
        $query = Project::query();
        if ($with !== []) {
            $query->with($with);
        } else {
            $query->with(['members:id', 'statuses', 'phases']);
        }

        $project = $query->find($projectId);
        if (! $project || ! $this->canSeeProject($user, $project)) {
            throw ApiException::notFound('api.projects.not_found');
        }

        return $project;
    }

    public function canCreateProject(User $user): bool
    {
        return $this->isCeo($user) || in_array($user->role, ['pm', 'monitor'], true);
    }

    public function canManageProjectSetup(User $user, Project $project): bool
    {
        return $this->isCeo($user) || (int) $project->pm_id === (int) $user->id;
    }

    public function canChangePm(User $user): bool
    {
        return $this->isCeo($user);
    }

    public function canUseTemplates(User $user): bool
    {
        return $this->canCreateProject($user);
    }

    public function canRemoveTemplate(User $user, ProjectTemplate $template): bool
    {
        return $this->isCeo($user) || (int) $template->created_by === (int) $user->id;
    }

    public function canTakePart(User $user, Project $project): bool
    {
        if ($user->role === 'admin') {
            return false;
        }

        return $this->isCeo($user) || $user->role === 'monitor' || $this->isMember($user, $project);
    }

    public function canManageTasks(User $user, Project $project): bool
    {
        return $this->isCeo($user)
            || (int) $project->pm_id === (int) $user->id
            || ($user->role === 'lead' && $this->isMember($user, $project));
    }

    public function canApprove(User $user, Project $project): bool
    {
        return $this->isCeo($user) || $this->canManageTasks($user, $project);
    }

    public function canMoveTask(User $user, Project $project, Task $task): bool
    {
        return $this->canManageTasks($user, $project)
            || ((int) $task->assignee_id === (int) $user->id && $this->isMember($user, $project));
    }

    public function canComment(User $user, Project $project): bool
    {
        return $this->canTakePart($user, $project);
    }

    public function canReportBug(User $user, Project $project): bool
    {
        return $this->canTakePart($user, $project);
    }

    public function canManageBugs(User $user, Project $project): bool
    {
        return $this->canManageTasks($user, $project) || $user->role === 'monitor';
    }

    public function canSeeReports(User $user): bool
    {
        return $this->isCeo($user) || in_array($user->role, ['pm', 'monitor', 'lead'], true);
    }

    public function canPostAnnouncements(User $user): bool
    {
        return $this->isCeo($user) || in_array($user->role, ['admin', 'monitor'], true);
    }

    public function canManageAnnouncement(User $user, Announcement $announcement): bool
    {
        return $this->isCeo($user) || (int) $announcement->author_id === (int) $user->id || $user->role === 'admin';
    }

    public function canSeePeoplePerf(User $user): bool
    {
        return $this->isCeo($user) || in_array($user->role, ['pm', 'monitor', 'lead'], true);
    }

    public function canManageCriteria(User $user): bool
    {
        return $this->isCeo($user);
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function canReview(User $user, User $subject, Collection $projects): bool
    {
        if ((int) $user->id === (int) $subject->id || in_array($subject->role, ['ceo', 'admin'], true)) {
            return false;
        }
        if ($this->isCeo($user)) {
            return true;
        }
        if ($user->role === 'pm') {
            return $projects->contains(fn (Project $p) => (int) $p->pm_id === (int) $user->id && $this->isMember($subject, $p));
        }
        if ($user->role === 'lead') {
            return $user->team_id !== null
                && (int) $subject->team_id === (int) $user->team_id
                && $subject->role === 'employee';
        }

        return false;
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function canSeePerson(User $user, User $subject, Collection $projects): bool
    {
        return $this->isCeo($user)
            || (int) $user->id === (int) $subject->id
            || $user->role === 'monitor'
            || $this->canReview($user, $subject, $projects);
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function canSeeReview(User $user, int $reviewerId, string $state, User $subject, Collection $projects): bool
    {
        if ($this->isCeo($user) || $reviewerId === (int) $user->id) {
            return true;
        }
        if ($state !== 'final') {
            return false;
        }

        return $this->canSeePerson($user, $subject, $projects);
    }

    public function canAdminister(User $user): bool
    {
        return $this->isCeo($user) || $user->role === 'admin';
    }

    public function canSeeAudit(User $user): bool
    {
        return $this->isCeo($user) || $user->role === 'admin';
    }

    public function canStartDirect(User $user, User $other): bool
    {
        return $this->isCeo($user) || ! $this->isCeo($other) || $user->role === 'monitor';
    }

    public function canWriteInDirect(User $user, User $other, bool $otherHasWritten): bool
    {
        return $this->canStartDirect($user, $other) || $otherHasWritten;
    }

    public function canCreateGroupChat(User $user): bool
    {
        return $this->isCeo($user) || in_array($user->role, ['monitor', 'pm', 'lead'], true);
    }

    public function canWriteNotes(User $user): bool
    {
        return $this->isCeo($user) || $user->role === 'monitor';
    }

    public function canSendRequests(User $user): bool
    {
        return ! $this->isCeo($user);
    }

    public function canSeeNote(User $user, MonitorNote $note): bool
    {
        if ($this->isCeo($user) || (int) $note->author_id === (int) $user->id) {
            return true;
        }
        if ($user->role === 'monitor' && $note->kind === 'request') {
            return true;
        }
        if ($note->relationLoaded('shares')) {
            return $note->shares->contains('user_id', (int) $user->id);
        }

        return $note->shares()->where('user_id', $user->id)->exists();
    }

    public function canTriageRequests(User $user): bool
    {
        return $this->isCeo($user) || $user->role === 'monitor';
    }

    public function canShareNote(User $user, MonitorNote $note): bool
    {
        return $note->kind !== 'request' && ($this->isCeo($user) || (int) $note->author_id === (int) $user->id);
    }

    public function canManageSessions(User $user): bool
    {
        return $this->isCeo($user) || $user->role === 'admin';
    }

    public function canSeeTeam(User $user, \App\Models\Team $team): bool
    {
        return $this->isCeo($user)
            || in_array($user->role, ['monitor', 'pm', 'admin'], true)
            || ($user->team_id !== null && (int) $user->team_id === (int) $team->id);
    }

    /**
     * @param  Collection<int, User>  $activeUsers
     * @param  Collection<int, Project>  $projects
     */
    public function worksForPm(int $pmId, \App\Models\Team $team, Collection $activeUsers, Collection $projects): bool
    {
        $teamMemberIds = $activeUsers
            ->where('team_id', (int) $team->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($teamMemberIds === []) {
            return false;
        }

        foreach ($projects as $project) {
            if ((int) $project->pm_id !== $pmId) {
                continue;
            }
            if (array_intersect($teamMemberIds, $project->memberIds()) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, User>  $activeUsers
     * @param  Collection<int, Project>  $projects
     */
    public function canRateTeam(User $user, \App\Models\Team $team, Collection $activeUsers, Collection $projects): bool
    {
        if ($this->isCeo($user) || $user->role === 'monitor') {
            return true;
        }

        return $user->role === 'pm' && $this->worksForPm((int) $user->id, $team, $activeUsers, $projects);
    }

    /**
     * @param  Collection<int, User>  $activeUsers
     * @param  Collection<int, Project>  $projects
     */
    public function canSeeTeamRatings(User $user, \App\Models\Team $team, Collection $activeUsers, Collection $projects): bool
    {
        return $this->isCeo($user)
            || $this->canRateTeam($user, $team, $activeUsers, $projects)
            || ($user->role !== 'admin' && $user->team_id !== null && (int) $user->team_id === (int) $team->id);
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function canRatePerson(User $user, User $person, Collection $projects): bool
    {
        if ((int) $user->id === (int) $person->id || ! in_array($person->role, ['pm', 'monitor', 'lead', 'employee'], true)) {
            return false;
        }
        if ($this->isCeo($user) || $user->role === 'monitor') {
            return true;
        }

        return $user->role === 'pm' && $projects->contains(
            fn (Project $p) => (int) $p->pm_id === (int) $user->id && $this->isMember($person, $p)
        );
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function canSeePersonRatings(User $user, User $person, Collection $projects): bool
    {
        return $this->isCeo($user)
            || $this->canRatePerson($user, $person, $projects)
            || (int) $user->id === (int) $person->id;
    }

    public function canSeeRating(User $user, \App\Models\Rating $rating): bool
    {
        return $this->isCeo($user) || ! $rating->private || (int) $rating->rater_id === (int) $user->id;
    }

    public function canChangeRating(User $user, \App\Models\Rating $rating, ?\Carbon\CarbonInterface $now = null): bool
    {
        if ($this->isCeo($user)) {
            return true;
        }
        if ((int) $rating->rater_id !== (int) $user->id) {
            return false;
        }
        $now ??= now('UTC');

        return $rating->created_at !== null
            && $rating->created_at->copy()->addHours(24)->gt($now);
    }

    public function canSendToCeo(User $user, MonitorNote $note): bool
    {
        return $this->isCeo($user) || (int) $note->author_id === (int) $user->id;
    }

    public function canUseCeoInbox(User $user): bool
    {
        return $this->isCeo($user);
    }

    public function bugNextStatuses(User $user, Project $project, Bug $bug): array
    {
        $next = [
            'reported' => [['to' => 'assigned', 'who' => 'managers']],
            'assigned' => [['to' => 'fixing', 'who' => 'developer']],
            'fixing' => [['to' => 'testing', 'who' => 'developer']],
            'testing' => [['to' => 'closed', 'who' => 'checker'], ['to' => 'reopened', 'who' => 'checker']],
            'reopened' => [['to' => 'fixing', 'who' => 'developer']],
            'closed' => [['to' => 'reopened', 'who' => 'checker']],
        ];

        $isManager = $this->canManageBugs($user, $project);
        $allowed = [];
        foreach ($next[$bug->status] ?? [] as $edge) {
            if (
                $isManager
                || ($edge['who'] === 'developer' && (int) $bug->assignee_id === (int) $user->id)
                || ($edge['who'] === 'checker' && (int) $bug->reported_by === (int) $user->id)
            ) {
                $allowed[] = $edge['to'];
            }
        }

        return $allowed;
    }
}
