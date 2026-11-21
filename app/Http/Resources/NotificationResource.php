<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'kind' => $this->kind,
            'actor_id' => $this->actor_id !== null ? (int) $this->actor_id : null,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'task_id' => $this->task_id !== null ? (int) $this->task_id : null,
            'announcement_id' => $this->announcement_id !== null ? (int) $this->announcement_id : null,
            'bug_id' => $this->bug_id !== null ? (int) $this->bug_id : null,
            'chat_key' => $this->chat_key,
            'data' => (object) ($this->data ?? []),
            'at' => BaghdadTime::iso($this->at),
            'read' => (bool) $this->read,
        ]);
    }
}
