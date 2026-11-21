<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Services\AccessService;

class AnnouncementPolicy
{
    public function __construct(private readonly AccessService $access) {}

    public function create(User $user): bool
    {
        return $this->access->canPostAnnouncements($user);
    }

    public function manage(User $user, Announcement $announcement): bool
    {
        return $this->access->canManageAnnouncement($user, $announcement);
    }
}
