<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'pm_id' => (int) $this->pm_id,
            'member_ids' => $this->members->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'start' => $this->start,
            'end' => $this->end,
            'state' => $this->state,
            'priority' => $this->priority,
            'uses_sprints' => (bool) $this->uses_sprints,
            'statuses' => $this->statuses->map(fn ($s) => [
                'key' => $s->key,
                'name' => $s->name,
                'name_en' => $s->name_en,
                'tone' => $s->tone,
            ])->values()->all(),
            'phases' => $this->phases->map(fn ($ph) => [
                'id' => (int) $ph->id,
                'name' => $ph->name,
            ])->values()->all(),
        ]);
    }
}
