<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessService;

class TaskPolicy
{
    public function __construct(private readonly AccessService $access) {}

    public function view(User $user, Task $task): bool
    {
        return $this->access->canSeeProject($user, $task->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->access->canManageTasks($user, $project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->access->canManageTasks($user, $task->project);
    }

    public function move(User $user, Task $task): bool
    {
        return $this->access->canMoveTask($user, $task->project, $task);
    }

    public function approve(User $user, Task $task): bool
    {
        return $this->access->canApprove($user, $task->project);
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->access->canComment($user, $task->project);
    }
}
