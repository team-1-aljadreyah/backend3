<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BugEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'bug_id' => (int) $this->bug_id,
            'actor_id' => (int) $this->actor_id,
            'kind' => $this->kind,
            'from' => $this->from,
            'to' => $this->to,
            'note' => $this->note,
            'at' => BaghdadTime::iso($this->at),
        ]);
    }
}
