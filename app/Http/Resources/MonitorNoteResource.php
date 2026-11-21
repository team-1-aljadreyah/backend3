<?php

namespace App\Http\Resources;

use App\Support\ApiResponse;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitorNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $about = ApiResponse::clean([
            'type' => $this->about_type,
            'id' => $this->about_id !== null ? (int) $this->about_id : null,
            'label' => $this->about_label,
            'label_en' => $this->about_label_en,
        ]);

        return ApiResponse::clean([
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'about' => $about,
            'tone' => $this->tone,
            'subject' => $this->subject,
            'people' => ($this->people !== null && $this->people !== []) ? $this->people : null,
            'author_id' => (int) $this->author_id,
            'created_at' => BaghdadTime::iso($this->created_at),
            'sent_at' => BaghdadTime::iso($this->sent_at),
            'ceo_state' => $this->ceo_state,
            'ceo_reply' => $this->ceo_reply,
            'replied_at' => BaghdadTime::iso($this->replied_at),
            'facts' => $this->facts,
        ]);
    }
}
