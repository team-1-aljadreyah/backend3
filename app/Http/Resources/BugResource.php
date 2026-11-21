<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BugResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'code' => $this->code,
            'project_id' => (int) $this->project_id,
            'phase_id' => $this->phase_id !== null ? (int) $this->phase_id : null,
            'task_id' => $this->task_id !== null ? (int) $this->task_id : null,
            'title' => $this->title,
            'description' => $this->description ?? '',
            'severity' => $this->severity,
            'priority' => $this->priority,
            'status' => $this->status,
            'reported_by' => (int) $this->reported_by,
            'assignee_id' => $this->assignee_id !== null ? (int) $this->assignee_id : null,
            'due' => $this->due,
            'created_at' => BaghdadTime::iso($this->created_at),
            'updated_at' => BaghdadTime::iso($this->updated_at),
            'closed_at' => BaghdadTime::iso($this->closed_at),
        ]);
    }
}
