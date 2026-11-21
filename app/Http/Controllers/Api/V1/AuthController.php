<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->input('identifier', ''),
            (string) $request->input('password', '')
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => (new UserResource($result['user']))->resolve($request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success((new UserResource($request->user()))->resolve($request));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(null);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()->orderBy('id')->get();

        return ApiResponse::success(UserResource::collection($users)->resolve($request));
    }
}
