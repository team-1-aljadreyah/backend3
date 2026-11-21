<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'statuses' => $this->statuses,
            'phases' => $this->phases,
            'uses_sprints' => (bool) $this->uses_sprints,
            'tasks' => $this->tasks,
            'from_project' => $this->from_project,
            'created_by' => (int) $this->created_by,
            'created_at' => BaghdadTime::iso($this->created_at),
        ]);
    }
}
