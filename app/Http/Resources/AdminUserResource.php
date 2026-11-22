<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $security = $this->loginSecurity;
        $viewer = $request->user();

        return array_merge(
            UserResource::baseFields($this->resource, $viewer instanceof User ? $viewer : null),
            ApiResponse::clean([
                'failed_logins' => (int) ($security?->failed_logins ?? 0),
                'locked_until' => BaghdadTime::iso($security?->locked_until),
                'last_login_at' => BaghdadTime::iso($security?->last_login_at),
                'created_at' => BaghdadTime::iso($this->created_at),
            ])
        );
    }
}
