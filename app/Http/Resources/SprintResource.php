<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = BaghdadTime::today();
        $plannedRatio = $this->closed_at !== null
            ? 1.0
            : BaghdadTime::plannedRatio($this->start, $this->end, $today);

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'name' => $this->name,
            'start' => $this->start,
            'end' => $this->end,
            'planned_ratio' => $plannedRatio,
            'closed_at' => BaghdadTime::iso($this->closed_at),
            'result' => $this->result,
        ]);
    }
}
