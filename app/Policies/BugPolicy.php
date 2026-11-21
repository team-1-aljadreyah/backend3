<?php

namespace App\Policies;

use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use App\Services\AccessService;

class BugPolicy
{
    public function __construct(private readonly AccessService $access) {}

    public function view(User $user, Bug $bug): bool
    {
        return $this->access->canSeeProject($user, $bug->project);
    }

    public function report(User $user, Project $project): bool
    {
        return $this->access->canReportBug($user, $project);
    }

    public function update(User $user, Bug $bug): bool
    {
        return $this->access->canManageBugs($user, $bug->project);
    }
}
