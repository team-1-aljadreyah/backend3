<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'reviewer_id' => (int) $this->reviewer_id,
            'period' => [
                'from' => $this->period_from,
                'to' => $this->period_to,
            ],
            'scores' => $this->scores ?? (object) [],
            'criteria' => $this->criteria ?? [],
            'total' => (int) $this->total,
            'note' => $this->note ?? '',
            'perf' => $this->perf,
            'state' => $this->state,
            'created_at' => BaghdadTime::iso($this->created_at),
            'finalized_at' => BaghdadTime::iso($this->finalized_at),
        ]);
    }
}
