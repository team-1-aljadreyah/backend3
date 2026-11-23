<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\URL;

class AttachmentUrlService
{
    public function signedDownloadUrl(Attachment $attachment, int $userId): string
    {
        // Round to the next full hour (+>=60m) so repeated polls receive identical URLs for browser caching & playback.
        $expires = now('UTC')->addMinutes(60)->startOfHour()->addHour();

        return URL::temporarySignedRoute(
            'attachments.download',
            $expires,
            [
                'attachment' => $attachment->id,
                'uid' => $userId,
            ]
        );
    }
}
