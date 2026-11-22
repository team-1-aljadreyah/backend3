<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\Bug;
use App\Models\BugEvent;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_FILES = 30;

    public const MAX_VOICE_BYTES = 5 * 1024 * 1024;

    public const MAX_VOICE_SECONDS = 300;

    public const VOICE_PEAKS = 48;

    public const VOICE_EXTENSIONS = ['webm', 'weba', 'ogg', 'oga', 'opus', 'mp4', 'm4a', 'aac', 'mp3'];

    public const VOICE_MIMES = [
        'webm' => 'audio/webm',
        'ogg' => 'audio/ogg',
        'mp4' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'aac' => 'audio/aac',
    ];

    public const ALLOWED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip',
    ];

    private const EXTENSION_MIMES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    private const CANONICAL_MIMES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
    ];

    private const BLOCKED_MIMES = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'application/x-php',
        'text/x-php',
        'application/x-httpd-php',
        'application/x-executable',
        'application/x-dosexec',
    ];

    public function __construct(
        private readonly AccessService $access,
        private readonly ChatService $chat,
    ) {}

    /**
     * @return Collection<int, Attachment>
     */
    public function list(User $user, string $ownerType, int $ownerId): Collection
    {
        $this->authorizeAccess($user, $ownerType, $ownerId);

        return Attachment::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>|string|null  $rawVoice
     */
    public function upload(User $user, string $ownerType, int $ownerId, ?UploadedFile $file, array|string|null $rawVoice = null): Attachment
    {
        $perms = $this->authorizeAccess($user, $ownerType, $ownerId);
        if (! $perms['canAdd']) {
            throw ApiException::forbidden('api.attachments.upload_forbidden');
        }

        if ($rawVoice !== null && $rawVoice !== '') {
            return $this->uploadVoice($user, $ownerType, $ownerId, $file, $rawVoice);
        }

        if (! $file || ! $file->isValid()) {
            throw ApiException::validation('file', 'api.attachments.file_required');
        }

        $originalName = $file->getClientOriginalName();
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ownerType === 'message' && in_array($ext, self::VOICE_EXTENSIONS, true)) {
            throw ApiException::validation('voice', 'api.attachments.voice_missing_meta');
        }
        if ($ext === '' || ! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw ApiException::validation('file', 'api.attachments.type_not_allowed');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw ApiException::validation('file', 'api.attachments.empty_file');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw ApiException::validation('file', 'api.attachments.too_large');
        }

        $detectedMime = $this->detectRealMime($file);
        if (in_array($detectedMime, self::BLOCKED_MIMES, true)) {
            throw ApiException::validation('file', 'api.attachments.type_not_allowed');
        }
        if ($detectedMime !== null && ! in_array($detectedMime, self::EXTENSION_MIMES[$ext] ?? [], true)) {
            // Secondary guard: reject mismatched dangerous content (e.g., HTML disguised as .txt or .png)
            if (! (in_array($ext, ['txt', 'csv'], true) && str_starts_with($detectedMime, 'text/'))) {
                throw ApiException::validation('file', 'api.attachments.type_not_allowed');
            }
            if ($detectedMime === 'text/html') {
                throw ApiException::validation('file', 'api.attachments.type_not_allowed');
            }
        }

        // Additional SVG/HTML payload sniff for text-like or image uploads.
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv'], true)) {
            $head = strtolower((string) file_get_contents($file->getRealPath(), false, null, 0, 512));
            if (str_contains($head, '<svg') || str_contains($head, '<!doctype html') || str_contains($head, '<html') || str_contains($head, '<script')) {
                throw ApiException::validation('file', 'api.attachments.type_not_allowed');
            }
        }

        $existingCount = Attachment::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->count();
        if ($existingCount >= self::MAX_FILES) {
            throw ApiException::validation('file', 'api.attachments.max_files', ['max' => self::MAX_FILES]);
        }

        $storedName = Str::uuid()->toString().".{$ext}";
        $diskPath = $file->storeAs("attachments/{$ownerType}/{$ownerId}", $storedName, self::diskName());
        if ($diskPath === false) {
            throw ApiException::validation('file', 'api.attachments.file_required');
        }

        $displayName = mb_substr(basename($originalName), 0, 200);
        $mime = self::CANONICAL_MIMES[$ext] ?? ($detectedMime ?: 'application/octet-stream');

        return DB::transaction(function () use ($user, $ownerType, $ownerId, $displayName, $size, $mime, $diskPath) {
            $attachment = Attachment::create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'name' => $displayName,
                'size' => $size,
                'mime' => $mime,
                'disk_path' => $diskPath,
                'uploaded_by' => $user->id,
                'at' => now('UTC'),
            ]);

            $this->logHistory($ownerType, $ownerId, $user->id, 'attachment_added', $displayName);

            return $attachment;
        });
    }

    public function remove(User $user, int $attachmentId): void
    {
        $attachment = Attachment::query()->find($attachmentId);
        if (! $attachment) {
            throw ApiException::notFound('api.attachments.file_not_found');
        }

        $perms = $this->authorizeAccess($user, $attachment->owner_type, (int) $attachment->owner_id);
        if ((int) $attachment->uploaded_by !== (int) $user->id && ! $perms['canRemoveAny']) {
            throw ApiException::forbidden('api.attachments.delete_forbidden');
        }

        DB::transaction(function () use ($user, $attachment) {
            $diskPath = $attachment->disk_path;
            $ownerType = $attachment->owner_type;
            $ownerId = (int) $attachment->owner_id;
            $name = $attachment->name;

            $attachment->delete();
            $this->logHistory($ownerType, $ownerId, $user->id, 'attachment_removed', $name);

            if ($diskPath !== '' && Storage::disk(self::diskName())->exists($diskPath)) {
                Storage::disk(self::diskName())->delete($diskPath);
            }
        });
    }

    public function findForDownload(User $user, int $attachmentId): Attachment
    {
        $attachment = Attachment::query()->find($attachmentId);
        if (! $attachment) {
            throw ApiException::notFound('api.attachments.file_not_found');
        }

        $this->authorizeAccess($user, $attachment->owner_type, (int) $attachment->owner_id);

        if (! Storage::disk(self::diskName())->exists($attachment->disk_path)) {
            throw ApiException::notFound('api.attachments.file_not_found');
        }

        return $attachment;
    }

    public static function diskName(): string
    {
        $configured = (string) config('filesystems.default', 'local');

        return in_array($configured, ['local', 'private'], true) ? $configured : 'local';
    }

    /**
     * @return array{canAdd: bool, canRemoveAny: bool}
     */
    private function authorizeAccess(User $user, string $ownerType, int $ownerId): array
    {
        if ($ownerType === 'message') {
            $message = Message::query()->find($ownerId);
            if (! $message) {
                throw ApiException::notFound('api.attachments.not_found');
            }
            $members = $this->chat->chatMembers($message->chat_key);
            if ($members === null || ! in_array((int) $user->id, $members, true)) {
                throw ApiException::notFound('api.attachments.not_found');
            }

            return [
                'canAdd' => (int) $message->author_id === (int) $user->id,
                'canRemoveAny' => false,
            ];
        }

        $projectId = match ($ownerType) {
            'project' => $ownerId,
            'task' => Task::query()->find($ownerId)?->project_id,
            'bug' => Bug::query()->find($ownerId)?->project_id,
            default => null,
        };

        $project = $projectId !== null
            ? Project::query()->with('members:id')->find($projectId)
            : null;

        if (! $project || ! $this->access->canSeeProject($user, $project)) {
            throw ApiException::notFound('api.attachments.not_found');
        }

        return [
            'canAdd' => $this->access->canTakePart($user, $project),
            'canRemoveAny' => $this->access->canManageProjectSetup($user, $project),
        ];
    }

    private function logHistory(string $ownerType, int $ownerId, int $actorId, string $kind, string $name): void
    {
        if ($ownerType === 'message') {
            return;
        }

        $now = now('UTC');
        $from = $kind === 'attachment_removed' ? $name : null;
        $to = $kind === 'attachment_added' ? $name : null;

        match ($ownerType) {
            'task' => TaskEvent::create(['task_id' => $ownerId, 'actor_id' => $actorId, 'kind' => $kind, 'from' => $from, 'to' => $to, 'at' => $now]),
            'bug' => BugEvent::create(['bug_id' => $ownerId, 'actor_id' => $actorId, 'kind' => $kind, 'from' => $from, 'to' => $to, 'at' => $now]),
            'project' => ProjectEvent::create(['project_id' => $ownerId, 'actor_id' => $actorId, 'kind' => $kind, 'from' => $from, 'to' => $to, 'at' => $now]),
            default => null,
        };
    }

    private function detectRealMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            return null;
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) ? strtolower($mime) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|string  $rawVoice
     */
    private function uploadVoice(User $user, string $ownerType, int $ownerId, ?UploadedFile $file, array|string $rawVoice): Attachment
    {
        if ($ownerType !== 'message') {
            throw ApiException::validation('voice', 'api.attachments.voice_message_only');
        }

        if (! $file || ! $file->isValid()) {
            throw ApiException::validation('file', 'api.attachments.file_required');
        }

        $voice = is_string($rawVoice) ? json_decode($rawVoice, true) : $rawVoice;
        if (! is_array($voice)) {
            throw ApiException::validation('voice', 'api.attachments.voice_peaks');
        }

        $hasVoiceAlready = Attachment::query()
            ->where('owner_type', 'message')
            ->where('owner_id', $ownerId)
            ->whereNotNull('voice')
            ->exists();
        if ($hasVoiceAlready) {
            throw ApiException::validation('file', 'api.attachments.voice_duplicate');
        }

        $originalName = $file->getClientOriginalName();
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || ! in_array($ext, self::VOICE_EXTENSIONS, true)) {
            throw ApiException::validation('file', 'api.attachments.voice_type_not_allowed');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw ApiException::validation('file', 'api.attachments.voice_empty');
        }
        if ($size > self::MAX_VOICE_BYTES) {
            throw ApiException::validation('file', 'api.attachments.voice_too_large');
        }

        $duration = $voice['duration_s'] ?? null;
        if ((! is_int($duration) && ! is_float($duration)) || ! is_finite((float) $duration) || (float) $duration <= 0 || (float) $duration > self::MAX_VOICE_SECONDS) {
            throw ApiException::validation('voice', 'api.attachments.voice_duration');
        }

        $peaks = $voice['peaks'] ?? null;
        if (! is_array($peaks) || count($peaks) !== self::VOICE_PEAKS) {
            throw ApiException::validation('voice', 'api.attachments.voice_peaks');
        }
        $normalizedPeaks = [];
        foreach ($peaks as $p) {
            if (! is_int($p) || $p < 0 || $p > 100) {
                throw ApiException::validation('voice', 'api.attachments.voice_peaks');
            }
            $normalizedPeaks[] = $p;
        }

        $realPath = $file->getRealPath();
        $head = ($realPath && is_file($realPath)) ? (string) file_get_contents($realPath, false, null, 0, 16) : '';
        $kind = $this->sniffAudio($head);
        if ($kind === null) {
            throw ApiException::validation('file', 'api.attachments.voice_type_not_allowed');
        }
        if ($kind === 'mp4' && $realPath && is_file($realPath)) {
            $sample = (string) file_get_contents($realPath, false, null, 0, 65536);
            if (str_contains($sample, 'vide') || str_contains($sample, 'avc1') || str_contains($sample, 'hvc1')) {
                throw ApiException::validation('file', 'api.attachments.voice_type_not_allowed');
            }
        }

        $existingCount = Attachment::query()
            ->where('owner_type', 'message')
            ->where('owner_id', $ownerId)
            ->count();
        if ($existingCount >= self::MAX_FILES) {
            throw ApiException::validation('file', 'api.attachments.max_files', ['max' => self::MAX_FILES]);
        }

        $storedName = Str::uuid()->toString().".{$ext}";
        $diskPath = $file->storeAs("attachments/{$ownerType}/{$ownerId}", $storedName, self::diskName());
        if ($diskPath === false) {
            throw ApiException::validation('file', 'api.attachments.file_required');
        }

        $displayName = mb_substr(basename($originalName), 0, 200);
        $mime = self::VOICE_MIMES[$kind];
        $voiceMeta = [
            'duration_s' => round((float) $duration, 1),
            'peaks' => $normalizedPeaks,
        ];

        return Attachment::create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'name' => $displayName,
            'size' => $size,
            'mime' => $mime,
            'voice' => $voiceMeta,
            'disk_path' => $diskPath,
            'uploaded_by' => $user->id,
            'at' => now('UTC'),
        ]);
    }

    public function sniffAudio(string $head): ?string
    {
        if (strlen($head) < 4) {
            return null;
        }
        if (str_starts_with($head, "\x1a\x45\xdf\xa3")) {
            return 'webm';
        }
        if (str_starts_with($head, 'OggS')) {
            return 'ogg';
        }
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
            return 'mp4';
        }
        if (str_starts_with($head, 'ID3')) {
            return 'mp3';
        }
        $b0 = ord($head[0]);
        $b1 = ord($head[1]);
        if ($b0 === 0xff && ($b1 & 0xe0) === 0xe0) {
            return ($b1 & 0x06) === 0 ? 'aac' : 'mp3';
        }

        return null;
    }
}
