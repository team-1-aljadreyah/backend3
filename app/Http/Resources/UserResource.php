<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static function baseFields(mixed $user): array
    {
        return ApiResponse::clean([
            'id' => (int) $user->id,
            'name' => $user->name,
            'name_en' => $user->name_en,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'custom_role' => $user->custom_role,
            'title' => $user->title,
            'team_id' => $user->team_id !== null ? (int) $user->team_id : null,
            'active' => (bool) $user->active,
            'avatar_url' => $user->avatarUrl(),
        ], ['name_en', 'phone', 'team_id']);
    }

    public function toArray(Request $request): array
    {
        return self::baseFields($this->resource);
    }
}
