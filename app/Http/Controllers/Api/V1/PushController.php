<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PushService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PushController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    public function store(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $sessionId = ($token instanceof PersonalAccessToken) ? $token->session_id : null;

        $this->push->subscribe($request->user(), $sessionId, $request->all());

        return ApiResponse::success(null);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->push->unsubscribe($request->user(), (string) $request->input('endpoint', ''));

        return ApiResponse::success(null);
    }
}
