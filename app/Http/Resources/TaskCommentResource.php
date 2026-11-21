<?php

namespace App\Http\Resources;

use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'task_id' => (int) $this->task_id,
            'author_id' => (int) $this->author_id,
            'body' => $this->body,
            'created_at' => BaghdadTime::iso($this->created_at),
        ];
    }
}
