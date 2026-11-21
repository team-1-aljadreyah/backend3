<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ApiResponse::success(NotificationResource::collection($list)->resolve($request));
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $n = Notification::query()->find($id);
        if (! $n || (int) $n->user_id !== (int) $request->user()->id) {
            throw ApiException::notFound('api.notifications.not_found');
        }

        if (! $n->read) {
            $n->read = true;
            $n->save();
        }

        return ApiResponse::success(null);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->update(['read' => true]);

        return ApiResponse::success(null);
    }

    public function preferences(Request $request): JsonResponse
    {
        $prefs = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->pluck('enabled', 'kind')
            ->map(fn ($v) => (bool) $v)
            ->all();

        return ApiResponse::success((object) $prefs);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $input = $request->all();
        foreach ($input as $kind => $on) {
            if (! in_array($kind, Notification::KINDS, true)) {
                throw ApiException::validation('prefs', 'api.notifications.unknown_kind');
            }
            if (! is_bool($on)) {
                throw ApiException::validation('prefs', 'api.notifications.invalid_value');
            }
            if (! $on && in_array($kind, Notification::REQUIRED_KINDS, true)) {
                throw ApiException::validation('prefs', 'api.notifications.required_kind');
            }
        }

        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($userId, $input) {
            NotificationPreference::query()->where('user_id', $userId)->delete();
            foreach ($input as $kind => $on) {
                NotificationPreference::create([
                    'user_id' => $userId,
                    'kind' => $kind,
                    'enabled' => $on,
                ]);
            }
        });

        return ApiResponse::success((object) $input);
    }
}
