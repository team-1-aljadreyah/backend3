<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $approval = null;
        if ($this->relationLoaded('currentApproval') && $this->currentApproval && $this->currentApproval->state !== 'cancelled') {
            $a = $this->currentApproval;
            $approval = ApiResponse::clean([
                'state' => $a->state,
                'from' => $a->from_status,
                'to' => $a->to_status,
                'requested_by' => (int) $a->requested_by,
                'requested_at' => BaghdadTime::iso($a->requested_at),
                'decided_by' => $a->decided_by !== null ? (int) $a->decided_by : null,
                'decided_at' => BaghdadTime::iso($a->decided_at),
                'note' => $a->note,
            ]);
        }

        $dependsOn = $this->relationLoaded('dependencies')
            ? $this->dependencies->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : null;

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'code' => $this->code,
            'project_id' => (int) $this->project_id,
            'phase_id' => $this->phase_id !== null ? (int) $this->phase_id : null,
            'sprint_id' => $this->sprint_id !== null ? (int) $this->sprint_id : null,
            'title' => $this->title,
            'description' => $this->description ?? '',
            'depends_on' => ($dependsOn !== null && $dependsOn !== []) ? $dependsOn : null,
            'assignee_id' => $this->assignee_id !== null ? (int) $this->assignee_id : null,
            'start' => $this->start,
            'due' => $this->due,
            'priority' => $this->priority,
            'status' => $this->status,
            'blocked_reason' => $this->blocked_reason,
            'approval' => $approval,
            'subtasks_done' => (int) $this->subtasks_done,
            'subtasks_total' => (int) $this->subtasks_total,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => BaghdadTime::iso($this->created_at),
            'updated_at' => BaghdadTime::iso($this->updated_at),
        ]);
    }
}
