<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RatingResource;
use App\Services\RatingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function __construct(private readonly RatingService $ratings) {}

    public function teamSummaries(Request $request): JsonResponse
    {
        return ApiResponse::success($this->ratings->teamSummaries($request->user(), $request));
    }

    public function teamDetail(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->ratings->teamDetail($request->user(), $id, $request));
    }

    public function teamRatings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->ratings->listForSubject($request->user(), 'team', $id, $request));
    }

    public function rateTeam(Request $request, int $id): JsonResponse
    {
        $rating = $this->ratings->create($request->user(), 'team', $id, $request->all());

        return ApiResponse::success((new RatingResource($rating))->resolve($request), 201);
    }

    public function peopleSummaries(Request $request): JsonResponse
    {
        return ApiResponse::success($this->ratings->peopleSummaries($request->user()));
    }

    public function personRatings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->ratings->listForSubject($request->user(), 'person', $id, $request));
    }

    public function ratePerson(Request $request, int $id): JsonResponse
    {
        $rating = $this->ratings->create($request->user(), 'person', $id, $request->all());

        return ApiResponse::success((new RatingResource($rating))->resolve($request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rating = $this->ratings->update($request->user(), $id, $request->all());

        return ApiResponse::success((new RatingResource($rating))->resolve($request));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ratings->delete($request->user(), $id);

        return ApiResponse::success(null);
    }
}
