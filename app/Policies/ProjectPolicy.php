<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\AccessService;

class ProjectPolicy
{
    public function __construct(private readonly AccessService $access) {}

    public function view(User $user, Project $project): bool
    {
        return $this->access->canSeeProject($user, $project);
    }

    public function create(User $user): bool
    {
        return $this->access->canCreateProject($user);
    }

    public function manageSetup(User $user, Project $project): bool
    {
        return $this->access->canManageProjectSetup($user, $project);
    }

    public function changePm(User $user): bool
    {
        return $this->access->canChangePm($user);
    }

    public function takePart(User $user, Project $project): bool
    {
        return $this->access->canTakePart($user, $project);
    }
}
