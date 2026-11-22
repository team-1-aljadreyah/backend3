<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static function baseFields(mixed $user, ?User $viewer = null): array
    {
        $birthRaw = $user->birth_date instanceof CarbonInterface
            ? $user->birth_date->format('Y-m-d')
            : (is_string($user->birth_date) && $user->birth_date !== '' ? substr($user->birth_date, 0, 10) : null);

        $canSeeBirthDate = $viewer === null
            || $viewer->isCeo()
            || $viewer->role === 'admin'
            || (int) $viewer->id === (int) $user->id;

        $birthday = $birthRaw !== null ? substr($birthRaw, 5, 5) : null;

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
            'birth_date' => $canSeeBirthDate ? $birthRaw : null,
            'birthday' => $birthday,
        ], ['name_en', 'phone', 'team_id']);
    }

    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return self::baseFields($this->resource, $viewer instanceof User ? $viewer : null);
    }
}
