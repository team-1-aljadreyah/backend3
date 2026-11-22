<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = null;
        if ($this->period_from !== null && $this->period_to !== null) {
            $from = $this->period_from instanceof CarbonInterface
                ? $this->period_from->format('Y-m-d')
                : substr((string) $this->period_from, 0, 10);
            $to = $this->period_to instanceof CarbonInterface
                ? $this->period_to->format('Y-m-d')
                : substr((string) $this->period_to, 0, 10);
            $period = ['from' => $from, 'to' => $to];
        }

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'subject_type' => $this->subject_type,
            'subject_id' => (int) $this->subject_id,
            'rater_id' => (int) $this->rater_id,
            'score' => (int) $this->score,
            'reason_type' => $this->reason_type,
            'reason' => $this->reason,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'project_name' => $this->project_name,
            'project_name_en' => $this->project_name_en,
            'period' => $period,
            'private' => (bool) $this->private,
            'created_at' => BaghdadTime::iso($this->created_at),
            'edited_at' => BaghdadTime::iso($this->edited_at),
            'edited_by' => $this->edited_by !== null ? (int) $this->edited_by : null,
        ]);
    }
}
