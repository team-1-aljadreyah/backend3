<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;
use App\Services\AccessService;

class TemplatePolicy
{
    public function __construct(private readonly AccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canUseTemplates($user);
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $this->access->canRemoveTemplate($user, $template);
    }
}
