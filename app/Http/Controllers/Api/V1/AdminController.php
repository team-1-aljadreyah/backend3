<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use App\Services\AdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function users(Request $request): JsonResponse
    {
        $users = $this->admin->users($request->user());

        return ApiResponse::success(AdminUserResource::collection($users)->resolve($request));
    }

    public function createUser(Request $request): JsonResponse
    {
        $user = $this->admin->createUser($request->user(), $request->all());

        return ApiResponse::success((new UserResource($user))->resolve($request), 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = $this->admin->updateUser($request->user(), $id, $request->all());

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function teams(Request $request): JsonResponse
    {
        return ApiResponse::success(TeamResource::collection($this->admin->teams())->resolve($request));
    }

    public function createTeam(Request $request): JsonResponse
    {
        $team = $this->admin->createTeam($request->user(), $request->all());

        return ApiResponse::success((new TeamResource($team))->resolve($request), 201);
    }

    public function updateTeam(Request $request, int $id): JsonResponse
    {
        $team = $this->admin->updateTeam($request->user(), $id, $request->all());

        return ApiResponse::success((new TeamResource($team))->resolve($request));
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->admin->settings($request->user()));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->admin->saveSettings($request->user(), $request->all()));
    }

    public function health(Request $request): JsonResponse
    {
        return ApiResponse::success($this->admin->health($request->user()));
    }

    public function audit(Request $request): JsonResponse
    {
        $entries = $this->admin->audit($request->user(), [
            'action' => $request->query('action'),
            'actor_id' => $request->query('actor_id'),
            'kind' => $request->query('kind'),
            'ip' => $request->query('ip'),
            'q' => $request->query('q'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        return ApiResponse::success(AuditLogResource::collection($entries)->resolve($request));
    }

    public function auditEntry(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->admin->auditEntry($request->user(), $id, $request));
    }
}
