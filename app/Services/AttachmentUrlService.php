<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\URL;

class AttachmentUrlService
{
    public function signedDownloadUrl(Attachment $attachment, int $userId): string
    {
        return URL::temporarySignedRoute(
            'attachments.download',
            now()->addMinutes(30),
            [
                'attachment' => $attachment->id,
                'uid' => $userId,
            ]
        );
    }
}
