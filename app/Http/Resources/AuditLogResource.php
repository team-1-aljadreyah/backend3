<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $target = ApiResponse::clean([
            'type' => $this->target_type,
            'id' => $this->target_id !== null ? (int) $this->target_id : null,
            'label' => $this->target_label,
        ]);

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'actor_id' => $this->actor_id !== null ? (int) $this->actor_id : null,
            'action' => $this->action,
            'target' => $target,
            'changes' => ($this->changes !== null && $this->changes !== []) ? $this->changes : null,
            'at' => BaghdadTime::iso($this->at),
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'session_id' => $this->session_id,
            'request_id' => $this->request_id,
        ]);
    }
}
