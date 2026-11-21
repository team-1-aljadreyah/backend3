<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProjectSummaryResource extends ProjectResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'tasks_total' => (int) ($this->tasks_total ?? 0),
            'tasks_done' => (int) ($this->tasks_done ?? 0),
            'tasks_late' => (int) ($this->tasks_late ?? 0),
            'tasks_blocked' => (int) ($this->tasks_blocked ?? 0),
        ]);
    }
}
