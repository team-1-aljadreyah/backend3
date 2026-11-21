<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\User;
use App\Services\AttachmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function listForTask(Request $request, int $id): JsonResponse
    {
        return $this->listOwner($request, 'task', $id);
    }

    public function uploadForTask(Request $request, int $id): JsonResponse
    {
        return $this->uploadOwner($request, 'task', $id);
    }

    public function listForProject(Request $request, int $id): JsonResponse
    {
        return $this->listOwner($request, 'project', $id);
    }

    public function uploadForProject(Request $request, int $id): JsonResponse
    {
        return $this->uploadOwner($request, 'project', $id);
    }

    public function listForBug(Request $request, int $id): JsonResponse
    {
        return $this->listOwner($request, 'bug', $id);
    }

    public function uploadForBug(Request $request, int $id): JsonResponse
    {
        return $this->uploadOwner($request, 'bug', $id);
    }

    public function listForMessage(Request $request, int $id): JsonResponse
    {
        return $this->listOwner($request, 'message', $id);
    }

    public function uploadForMessage(Request $request, int $id): JsonResponse
    {
        return $this->uploadOwner($request, 'message', $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->attachments->remove($request->user(), $id);

        return ApiResponse::success(null);
    }

    public function download(Request $request, int $attachment): StreamedResponse
    {
        if (! URL::hasValidSignature($request)) {
            throw ApiException::forbidden();
        }

        $uid = (int) $request->query('uid', 0);
        $user = User::query()->where('id', $uid)->where('active', true)->first();
        if (! $user) {
            throw new ApiException(401, 'UNAUTHENTICATED', __('api.unauthenticated'));
        }

        $record = $this->attachments->findForDownload($user, $attachment);

        return Storage::disk(AttachmentService::diskName())->download($record->disk_path, $record->name, [
            'Content-Type' => $record->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function listOwner(Request $request, string $ownerType, int $ownerId): JsonResponse
    {
        $list = $this->attachments->list($request->user(), $ownerType, $ownerId);

        return ApiResponse::success(AttachmentResource::collection($list)->resolve($request));
    }

    private function uploadOwner(Request $request, string $ownerType, int $ownerId): JsonResponse
    {
        $attachment = $this->attachments->upload($request->user(), $ownerType, $ownerId, $request->file('file'));

        return ApiResponse::success((new AttachmentResource($attachment))->resolve($request), 201);
    }
}
