<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $files = $this->files ?? collect();

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'chat' => $this->chat_key,
            'author_id' => (int) $this->author_id,
            'body' => $this->body,
            'reply_to' => $this->reply_to_id !== null ? (int) $this->reply_to_id : null,
            'mentions' => array_map('intval', $this->mentions ?? []),
            'created_at' => BaghdadTime::iso($this->created_at),
            'files' => AttachmentResource::collection($files)->resolve($request),
        ]);
    }
}
