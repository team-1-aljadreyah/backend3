<?php

namespace App\Http\Resources;

use App\Services\AttachmentUrlService;
use App\Support\BaghdadTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uid = (int) ($request->user()?->id ?? 0);
        $url = app(AttachmentUrlService::class)->signedDownloadUrl($this->resource, $uid);

        return [
            'id' => (int) $this->id,
            'owner_type' => $this->owner_type,
            'owner_id' => (int) $this->owner_id,
            'name' => $this->name,
            'size' => (int) $this->size,
            'mime' => $this->mime,
            'url' => $url,
            'uploaded_by' => (int) $this->uploaded_by,
            'at' => BaghdadTime::iso($this->at),
        ];
    }
}
