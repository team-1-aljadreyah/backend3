<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Services\PerformanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function overview(Request $request): JsonResponse
    {
        $data = $this->performance->overview($request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        return ApiResponse::success($data);
    }

    public function person(Request $request, int $id): JsonResponse
    {
        $detail = $this->performance->person($request->user(), $id, [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        $detail['reviews'] = ReviewResource::collection($detail['reviews'])->resolve($request);

        return ApiResponse::success($detail);
    }

    public function criteria(): JsonResponse
    {
        return ApiResponse::success($this->performance->criteria());
    }

    public function saveCriteria(Request $request): JsonResponse
    {
        $items = $request->input('items');
        $criteria = $this->performance->saveCriteria($request->user(), is_array($items) ? $items : []);

        return ApiResponse::success($criteria);
    }

    public function approveCriteria(Request $request): JsonResponse
    {
        return ApiResponse::success($this->performance->approveCriteria($request->user()));
    }

    public function createReview(Request $request): JsonResponse
    {
        $review = $this->performance->saveReview($request->user(), $request->all());

        return ApiResponse::success((new ReviewResource($review))->resolve($request), 201);
    }

    public function updateReview(Request $request, int $id): JsonResponse
    {
        $review = $this->performance->saveReview($request->user(), $request->all(), $id);

        return ApiResponse::success((new ReviewResource($review))->resolve($request));
    }

    public function finalizeReview(Request $request, int $id): JsonResponse
    {
        $review = $this->performance->finalizeReview($request->user(), $id);

        return ApiResponse::success((new ReviewResource($review))->resolve($request));
    }
}
