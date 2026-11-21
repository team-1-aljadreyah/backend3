<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $security = $this->loginSecurity;

        return array_merge(
            UserResource::baseFields($this->resource),
            ApiResponse::clean([
                'failed_logins' => (int) ($security?->failed_logins ?? 0),
                'locked_until' => BaghdadTime::iso($security?->locked_until),
                'last_login_at' => BaghdadTime::iso($security?->last_login_at),
            ])
        );
    }
}
