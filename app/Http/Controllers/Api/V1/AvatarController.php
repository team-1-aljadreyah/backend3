<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AttachmentService;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function update(Request $request): JsonResponse
    {
        $user = $this->profiles->setMyAvatar(
            $request->user(),
            $request->file('file'),
            $request->getContent(),
            $request->header('Content-Type')
        );

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function destroyOwn(Request $request): JsonResponse
    {
        $user = $this->profiles->removeMyAvatar($request->user());

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function destroyUser(Request $request, int $id): JsonResponse
    {
        $user = $this->profiles->removeUserAvatar($request->user(), $id);

        return ApiResponse::success((new UserResource($user))->resolve($request));
    }

    public function show(int $user, string $file): StreamedResponse
    {
        $avatar = $this->profiles->findForServing($user, $file);
        if (! $avatar) {
            throw ApiException::notFound();
        }

        $disk = AttachmentService::diskName();
        if (! Storage::disk($disk)->exists($avatar->disk_path)) {
            throw ApiException::notFound();
        }

        return Storage::disk($disk)->response($avatar->disk_path, null, [
            'Content-Type' => $avatar->mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
