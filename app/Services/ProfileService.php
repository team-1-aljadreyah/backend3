<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Avatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProfileService
{
    public const MAX_UPLOAD_BYTES = 200 * 1024;

    public const MAX_SIDE = 256;

    public function __construct(
        private readonly AccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function setMyAvatar(User $user, ?UploadedFile $file, ?string $rawBody = null, ?string $contentType = null): User
    {
        $raw = null;
        if ($file && $file->isValid()) {
            $raw = @file_get_contents($file->getRealPath());
        } else {
            $raw = $this->extractUploadedBytesFromPutRequest($rawBody, $contentType);
        }

        if ($raw === false || $raw === null || strlen($raw) === 0) {
            throw ApiException::validation('file', 'api.profile.file_empty');
        }
        if (strlen($raw) > self::MAX_UPLOAD_BYTES) {
            throw ApiException::validation('file', 'api.profile.file_too_large', ['kb' => self::MAX_UPLOAD_BYTES / 1024]);
        }

        if ($this->detectMagicKind($raw) === null) {
            throw ApiException::validation('file', 'api.profile.file_invalid_type');
        }

        ['bytes' => $cleanBytes, 'mime' => $mime, 'ext' => $ext, 'width' => $width, 'height' => $height] = $this->reencodeAndStripMetadata($raw);

        $disk = AttachmentService::diskName();
        $cacheKey = bin2hex(random_bytes(16));
        $diskPath = "avatars/{$user->id}/{$cacheKey}.{$ext}";

        if (! Storage::disk($disk)->put($diskPath, $cleanBytes)) {
            throw new RuntimeException('Unable to persist avatar image.');
        }

        $oldPaths = [];
        DB::transaction(function () use ($user, $diskPath, $mime, $cleanBytes, $width, $height, $cacheKey, &$oldPaths) {
            $oldPaths = Avatar::query()->where('user_id', $user->id)->pluck('disk_path')->all();
            Avatar::query()->where('user_id', $user->id)->delete();

            Avatar::create([
                'user_id' => $user->id,
                'disk_path' => $diskPath,
                'mime' => $mime,
                'size' => strlen($cleanBytes),
                'width' => $width,
                'height' => $height,
                'cache_key' => $cacheKey,
                'created_at' => now('UTC'),
            ]);
        });

        foreach ($oldPaths as $old) {
            if ($old !== '' && $old !== $diskPath && Storage::disk($disk)->exists($old)) {
                Storage::disk($disk)->delete($old);
            }
        }

        return $user->unsetRelation('avatar')->load('avatar');
    }

    public function removeMyAvatar(User $user): User
    {
        $this->deleteUserAvatars($user);

        return $user->unsetRelation('avatar')->load('avatar');
    }

    public function removeUserAvatar(User $actor, int $targetUserId): User
    {
        $target = User::query()->find($targetUserId);
        if (! $target) {
            throw ApiException::notFound('api.profile.user_not_found');
        }

        if (! $this->access->canAdminister($actor)) {
            throw ApiException::forbidden('api.profile.remove_forbidden');
        }

        $hadAvatar = $this->deleteUserAvatars($target);
        if ($hadAvatar && (int) $target->id !== (int) $actor->id) {
            $this->audit->log($actor->id, 'picture_removed', [
                'type' => 'user',
                'id' => $target->id,
                'label' => $target->name,
            ]);
        }

        return $target->unsetRelation('avatar')->load('avatar');
    }

    public function findForServing(int $userId, string $file): ?Avatar
    {
        $cacheKey = pathinfo($file, PATHINFO_FILENAME);
        if ($cacheKey === '' || preg_match('/^[a-f0-9]{32}$/', $cacheKey) !== 1) {
            return null;
        }

        return Avatar::query()
            ->where('user_id', $userId)
            ->where('cache_key', $cacheKey)
            ->first();
    }

    private function deleteUserAvatars(User $user): bool
    {
        $disk = AttachmentService::diskName();
        $records = Avatar::query()->where('user_id', $user->id)->get();
        if ($records->isEmpty()) {
            return false;
        }

        Avatar::query()->where('user_id', $user->id)->delete();
        foreach ($records as $rec) {
            if ($rec->disk_path !== '' && Storage::disk($disk)->exists($rec->disk_path)) {
                Storage::disk($disk)->delete($rec->disk_path);
            }
        }

        return true;
    }

    private function detectMagicKind(string $bytes): ?string
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        if (str_starts_with($bytes, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * Decode and re-draw image via GD to strip EXIF/metadata/payloads and cap at 256x256.
     *
     * @return array{bytes: string, mime: string, ext: string, width: int, height: int}
     */
    private function reencodeAndStripMetadata(string $raw): array
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The PHP gd extension is required for avatar image sanitization.');
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw ApiException::validation('file', 'api.profile.file_invalid_type');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            throw ApiException::validation('file', 'api.profile.file_invalid_type');
        }

        $scale = min(1.0, self::MAX_SIDE / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        $useWebp = function_exists('imagewebp');

        if ($useWebp) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        $ok = $useWebp ? imagewebp($dst, null, 85) : imagejpeg($dst, null, 88);
        $encoded = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (! $ok || $encoded === false || $encoded === '') {
            throw ApiException::validation('file', 'api.profile.file_invalid_type');
        }

        return [
            'bytes' => $encoded,
            'mime' => $useWebp ? 'image/webp' : 'image/jpeg',
            'ext' => $useWebp ? 'webp' : 'jpg',
            'width' => $dstW,
            'height' => $dstH,
        ];
    }

    /**
     * PHP SAPI does not populate $_FILES on PUT requests unless request_parse_body() (PHP 8.4+)
     * or multipart boundary extraction is used.
     */
    private function extractUploadedBytesFromPutRequest(?string $rawBody, ?string $contentType): ?string
    {
        if (function_exists('request_parse_body') && str_starts_with(strtolower((string) $contentType), 'multipart/form-data')) {
            try {
                [, $files] = @request_parse_body();
                if (is_array($files) && isset($files['file']['tmp_name']) && is_string($files['file']['tmp_name'])) {
                    $parsed = @file_get_contents($files['file']['tmp_name']);
                    if (is_string($parsed) && $parsed !== '') {
                        return $parsed;
                    }
                }
            } catch (\Throwable) {
                // Fall back to raw request content parsing when available
            }
        }

        if ($rawBody === null || $rawBody === '' || $contentType === null) {
            return null;
        }
        if (preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $m) !== 1) {
            return null;
        }
        $boundary = $m[1] !== '' ? $m[1] : $m[2];
        $parts = explode("--{$boundary}", $rawBody);
        foreach ($parts as $part) {
            if ($part === '' || $part === "--\r\n" || $part === '--') {
                continue;
            }
            $split = preg_split("/\r?\n\r?\n/", ltrim($part, "\r\n"), 2);
            if (! is_array($split) || count($split) !== 2) {
                continue;
            }
            [$headers, $body] = $split;
            if (preg_match('/name="file"/i', $headers) === 1) {
                return preg_replace("/\r?\n$/", '', $body);
            }
        }

        return null;
    }
}
