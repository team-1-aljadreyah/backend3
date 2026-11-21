<?php

namespace App\Http\Resources;

use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'important' => (bool) $this->important,
            'author_id' => (int) $this->author_id,
            'created_at' => BaghdadTime::iso($this->created_at),
            'seen' => (bool) ($this->seen ?? false),
            'seen_count' => (int) ($this->seen_count ?? 0),
            'audience_count' => (int) ($this->audience_count ?? 0),
        ];
    }
}
