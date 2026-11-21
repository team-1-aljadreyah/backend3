<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Services\ChatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function conversations(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->conversations($request->user(), $request));
    }

    public function messages(Request $request, string $key): JsonResponse
    {
        $list = $this->chat->messages($request->user(), $key);

        return ApiResponse::success(MessageResource::collection($list)->resolve($request));
    }

    public function send(Request $request, string $key): JsonResponse
    {
        $message = $this->chat->send($request->user(), $key, $request->all());

        return ApiResponse::success((new MessageResource($message))->resolve($request), 201);
    }

    public function markRead(Request $request, string $key): JsonResponse
    {
        $this->chat->markRead($request->user(), $key);

        return ApiResponse::success(null);
    }

    public function startDirect(Request $request): JsonResponse
    {
        $conversation = $this->chat->startDirect($request->user(), (int) $request->input('user_id', 0), $request);

        return ApiResponse::success($conversation);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $conversation = $this->chat->createGroup($request->user(), $request->all(), $request);

        return ApiResponse::success($conversation, 201);
    }

    public function updateGroup(Request $request, string $key): JsonResponse
    {
        $conversation = $this->chat->updateGroup($request->user(), $key, $request->all(), $request);

        return ApiResponse::success($conversation);
    }

    public function leaveGroup(Request $request, string $key): JsonResponse
    {
        $this->chat->leaveGroup($request->user(), $key);

        return ApiResponse::success(null);
    }
}
