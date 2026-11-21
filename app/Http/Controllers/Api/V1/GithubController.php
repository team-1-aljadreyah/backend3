<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GithubService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubController extends Controller
{
    public function __construct(private readonly GithubService $github) {}

    public function settings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->github->settings($request->user(), $id));
    }

    public function saveSettings(Request $request, int $id): JsonResponse
    {
        $repos = $request->input('repos');
        $saved = $this->github->saveSettings(
            $request->user(),
            $id,
            (bool) $request->input('enabled', false),
            is_array($repos) ? $repos : []
        );

        return ApiResponse::success($saved);
    }

    public function taskLinks(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->github->links($request->user(), 'task', $id));
    }

    public function bugLinks(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->github->links($request->user(), 'bug', $id));
    }

    public function webhook(Request $request): JsonResponse
    {
        $res = $this->github->handleWebhook(
            (string) $request->header('X-GitHub-Event', ''),
            (string) $request->header('X-GitHub-Delivery', ''),
            $request->getContent(),
            (string) $request->header('X-Hub-Signature-256', ''),
            $request->json()->all()
        );

        return ApiResponse::success($res);
    }
}
