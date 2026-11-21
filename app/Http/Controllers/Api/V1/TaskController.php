<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskEventResource;
use App\Http\Resources\TaskResource;
use App\Services\TaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $list = $this->tasks->listForProject($request->user(), $id);

        return ApiResponse::success(TaskResource::collection($list)->resolve($request));
    }

    public function myTasks(Request $request): JsonResponse
    {
        $list = $this->tasks->myTasks($request->user());

        return ApiResponse::success(TaskResource::collection($list)->resolve($request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $task = $this->tasks->show($request->user(), $id);

        return ApiResponse::success((new TaskResource($task))->resolve($request));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $id, $request->all());

        return ApiResponse::success((new TaskResource($task))->resolve($request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = $this->tasks->update($request->user(), $id, $request->all());

        return ApiResponse::success((new TaskResource($task))->resolve($request));
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $res = $this->tasks->move(
            $request->user(),
            $id,
            (string) $request->input('status', ''),
            $request->input('blocked_reason')
        );

        return ApiResponse::success([
            'task' => (new TaskResource($res['task']))->resolve($request),
            'outcome' => $res['outcome'],
        ]);
    }

    public function decideApproval(Request $request, int $id): JsonResponse
    {
        $task = $this->tasks->decideCompletion(
            $request->user(),
            $id,
            (bool) $request->input('approve', false),
            $request->input('note')
        );

        return ApiResponse::success((new TaskResource($task))->resolve($request));
    }

    public function setDependencies(Request $request, int $id): JsonResponse
    {
        $dependsOn = $request->input('depends_on');
        $task = $this->tasks->setDependencies($request->user(), $id, is_array($dependsOn) ? $dependsOn : []);

        return ApiResponse::success((new TaskResource($task))->resolve($request));
    }

    public function comments(Request $request, int $id): JsonResponse
    {
        $comments = $this->tasks->comments($request->user(), $id);

        return ApiResponse::success(TaskCommentResource::collection($comments)->resolve($request));
    }

    public function addComment(Request $request, int $id): JsonResponse
    {
        $comment = $this->tasks->addComment($request->user(), $id, (string) $request->input('body', ''));

        return ApiResponse::success((new TaskCommentResource($comment))->resolve($request), 201);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $events = $this->tasks->history($request->user(), $id);

        return ApiResponse::success(TaskEventResource::collection($events)->resolve($request));
    }
}
