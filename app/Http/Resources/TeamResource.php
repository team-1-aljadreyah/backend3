<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ApiResponse::clean([
            'id' => (int) $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'lead_id' => $this->lead_id !== null ? (int) $this->lead_id : null,
        ]);
    }
}
