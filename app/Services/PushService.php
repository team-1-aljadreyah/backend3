<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendWebPushJob;
use App\Models\ChatGroup;
use App\Models\DirectConversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\PushSubscription;
use App\Support\BaghdadTime;
use App\Support\Birthday;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushService
{
    public const MAX_PAYLOAD_BYTES = 2900;

    public const STALE_DAYS = 120;

    /**
     * @param  array<string, mixed>  $input
     */
    public function subscribe(User $user, ?string $sessionId, array $input): PushSubscription
    {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint === '' || ! str_starts_with($endpoint, 'https://') || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw ApiException::validation('endpoint', 'api.push.endpoint_invalid');
        }

        $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));
        if ($p256dh === '' || $auth === '' || strlen(self::base64UrlDecode($p256dh)) !== 65 || strlen(self::base64UrlDecode($auth)) < 12) {
            throw ApiException::validation('keys', 'api.push.keys_invalid');
        }

        $device = mb_substr(trim((string) ($input['device'] ?? '')), 0, 120);
        if ($device === '') {
            throw ApiException::validation('device', 'api.push.device_required');
        }

        $hash = hash('sha256', $endpoint);
        $now = now('UTC');

        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();
        if ($existing) {
            $existing->forceFill([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'device' => $device,
                'last_used_at' => $now,
            ])->save();

            return $existing;
        }

        return PushSubscription::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'endpoint_hash' => $hash,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'device' => $device,
            'created_at' => $now,
            'last_used_at' => $now,
        ]);
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }

        PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete();
    }

    public function deleteForSessions(array $sessionIds): void
    {
        $ids = array_values(array_filter(array_unique($sessionIds)));
        if ($ids === []) {
            return;
        }

        PushSubscription::query()->whereIn('session_id', $ids)->delete();
    }

    public function pruneStale(): int
    {
        $cutoff = now('UTC')->subDays(self::STALE_DAYS);

        return PushSubscription::query()
            ->where(function ($q) use ($cutoff) {
                $q->where('last_used_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_used_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();
    }

    public function isConfigured(): bool
    {
        $pub = trim((string) config('services.vapid.public_key', ''));
        $priv = trim((string) config('services.vapid.private_key', ''));

        return $pub !== '' && $priv !== '';
    }

    public function queueForNotification(Notification $notification, ?User $actor = null): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subs = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->get();

        if ($subs->isEmpty()) {
            return;
        }

        $recipient = User::query()->find($notification->user_id);
        if (! $recipient || ! $recipient->active) {
            return;
        }

        $payload = $this->buildNotificationPayload($notification, $recipient, $actor);
        $urgency = $notification->kind === 'chat_mention' ? 'high' : 'normal';

        foreach ($subs as $sub) {
            SendWebPushJob::dispatch((int) $sub->id, $payload, $urgency, 86400);
        }
    }

    /**
     * @param  int[]  $memberIds
     */
    public function queueForChatMessage(Message $message, User $sender, string $chatKey, array $memberIds): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $recipientIds = array_values(array_filter(
            array_map('intval', $memberIds),
            fn (int $id) => $id !== (int) $sender->id
        ));

        if ($recipientIds === []) {
            return;
        }

        $subs = PushSubscription::query()
            ->whereIn('user_id', $recipientIds)
            ->get()
            ->groupBy('user_id');

        if ($subs->isEmpty()) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $subs->keys()->all())
            ->where('active', true)
            ->get()
            ->keyBy('id');

        $chatMeta = $this->resolveChatTitle($chatKey, (int) $sender->id);

        foreach ($subs as $uid => $userSubs) {
            $recipient = $recipients->get((int) $uid);
            if (! $recipient) {
                continue;
            }

            $payload = $this->buildChatPayload($message, $sender, $recipient, $chatKey, $chatMeta);
            foreach ($userSubs as $sub) {
                SendWebPushJob::dispatch((int) $sub->id, $payload, 'high', 86400);
            }
        }
    }

    /**
     * @return 'ok'|'gone'|'bad'|'retry'
     */
    public function sendToSubscription(PushSubscription $sub, array $payload, string $urgency = 'normal', int $ttl = 86400): string
    {
        if (! $this->isConfigured()) {
            return 'bad';
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return 'bad';
        }

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            $payload['body'] = mb_substr((string) ($payload['body'] ?? ''), 0, 120);
            $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $encrypted = $this->encryptPayload($json, $sub->p256dh, $sub->auth);
            $jwt = $this->buildVapidJwt($sub->endpoint);
            $publicKey = trim((string) config('services.vapid.public_key', ''));

            $response = Http::withHeaders([
                'Authorization' => "vapid t={$jwt}, k={$publicKey}",
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $ttl,
                'Urgency' => $urgency,
            ])
                ->withBody($encrypted, 'application/octet-stream')
                ->timeout(10)
                ->post($sub->endpoint);

            $status = $response->status();
            if ($status >= 200 && $status < 300) {
                $sub->last_used_at = now('UTC');
                $sub->save();

                return 'ok';
            }

            if ($status === 404 || $status === 410) {
                $sub->delete();

                return 'gone';
            }

            if ($status === 400 || $status === 413) {
                Log::warning('WebPush rejected by push service', [
                    'subscription_id' => $sub->id,
                    'status' => $status,
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return 'bad';
            }

            return 'retry';
        } catch (\Throwable $e) {
            Log::warning('WebPush delivery exception', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);

            return 'retry';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildNotificationPayload(Notification $n, User $recipient, ?User $actor = null): array
    {
        $lang = $recipient->preferred_locale === 'en' ? 'en' : 'ar';
        $dir = $lang === 'en' ? 'ltr' : 'rtl';
        $actor ??= $n->actor_id !== null ? User::query()->find($n->actor_id) : null;

        $who = $actor
            ? ($lang === 'en' && $actor->name_en ? $actor->name_en : $actor->name)
            : ($lang === 'en' ? 'FollowUP' : 'متابعة العمل');

        $title = $actor ? $who : ($lang === 'en' ? 'FollowUP' : 'متابعة العمل');
        $body = $this->notificationSentence($n, $recipient, $actor, $lang);
        $url = $this->notificationUrl($n, $recipient);
        $tag = ($n->kind === 'chat_mention' && $n->chat_key)
            ? "chat-{$n->chat_key}"
            : "n-{$n->id}";

        $out = [
            'title' => mb_substr($title, 0, 100),
            'body' => mb_substr($body, 0, 220),
            'url' => $url,
            'tag' => $tag,
            'lang' => $lang,
            'dir' => $dir,
        ];

        $icon = $actor?->avatarUrl();
        if (is_string($icon) && str_starts_with($icon, 'https://')) {
            $out['icon'] = $icon;
        }

        return $out;
    }

    /**
     * @param  array{kind: string, ar: string, en: string}  $chatMeta
     * @return array<string, mixed>
     */
    public function buildChatPayload(Message $message, User $sender, User $recipient, string $chatKey, array $chatMeta): array
    {
        $lang = $recipient->preferred_locale === 'en' ? 'en' : 'ar';
        $dir = $lang === 'en' ? 'ltr' : 'rtl';
        $senderName = $lang === 'en' && $sender->name_en ? $sender->name_en : $sender->name;
        $chatTitle = $lang === 'en' && $chatMeta['en'] !== '' ? $chatMeta['en'] : $chatMeta['ar'];

        $title = $chatMeta['kind'] === 'direct' || $chatTitle === ''
            ? $senderName
            : "{$senderName} · {$chatTitle}";

        $rawBody = trim((string) $message->body);
        $body = $rawBody !== ''
            ? mb_substr($rawBody, 0, 100)
            : ($lang === 'en' ? 'Sent a file' : 'أرسل ملفاً');

        $out = [
            'title' => mb_substr($title, 0, 120),
            'body' => $body,
            'url' => "/chat/{$chatKey}",
            'tag' => "chat-{$chatKey}",
            'lang' => $lang,
            'dir' => $dir,
        ];

        $icon = $sender->avatarUrl();
        if (is_string($icon) && str_starts_with($icon, 'https://')) {
            $out['icon'] = $icon;
        }

        return $out;
    }

    /**
     * @return array{kind: string, ar: string, en: string}
     */
    private function resolveChatTitle(string $chatKey, int $senderId): array
    {
        if (preg_match('/^([ptdg])-(\d+)$/', $chatKey, $m) !== 1) {
            return ['kind' => 'direct', 'ar' => '', 'en' => ''];
        }
        $id = (int) $m[2];
        if ($m[1] === 'p') {
            $p = Project::query()->find($id);

            return ['kind' => 'project', 'ar' => (string) ($p?->name ?? ''), 'en' => (string) ($p?->name_en ?? '')];
        }
        if ($m[1] === 't') {
            $t = Team::query()->find($id);

            return ['kind' => 'team', 'ar' => (string) ($t?->name ?? ''), 'en' => (string) ($t?->name_en ?? '')];
        }
        if ($m[1] === 'g') {
            $g = ChatGroup::query()->find($id);

            return ['kind' => 'group', 'ar' => (string) ($g?->name ?? ''), 'en' => (string) ($g?->name ?? '')];
        }
        if ($m[1] === 'd') {
            $d = DirectConversation::query()->find($id);
            unset($d, $senderId);

            return ['kind' => 'direct', 'ar' => '', 'en' => ''];
        }

        return ['kind' => 'direct', 'ar' => '', 'en' => ''];
    }

    private function notificationSentence(Notification $n, User $recipient, ?User $actor, string $lang): string
    {
        $ar = $lang === 'ar';
        $d = is_array($n->data) ? $n->data : [];
        $who = $actor
            ? ($ar || ! $actor->name_en ? $actor->name : $actor->name_en)
            : ($ar ? 'أحدهم' : 'Someone');
        $task = (string) ($d['task_code'] ?? '');
        $project = (string) (($ar ? ($d['project_name'] ?? '') : (($d['project_name_en'] ?? '') ?: ($d['project_name'] ?? ''))));

        return match ($n->kind) {
            'task_assigned' => $ar ? "{$who} أسند إليك {$task}" : "{$who} gave you {$task}",
            'task_status' => $ar
                ? "{$who} نقل {$task} إلى «".($d['status'] ?? '-').'»'
                : "{$who} moved {$task} to \"".($d['status_en'] ?? $d['status'] ?? '-').'"',
            'task_comment' => $ar ? "{$who} علّق على {$task}" : "{$who} commented on {$task}",
            'task_due_changed' => ! empty($d['due'])
                ? ($ar ? "{$who} غيّر موعد تسليم {$task} إلى {$d['due']}" : "{$who} moved {$task}'s due date to {$d['due']}")
                : ($ar ? "{$who} أزال موعد تسليم {$task}" : "{$who} removed {$task}'s due date"),
            'task_due_soon' => $ar ? "اقترب موعد تسليم {$task}" : "{$task} is due soon",
            'task_overdue' => $ar ? "تجاوزت {$task} موعد تسليمها" : "{$task} is past its due date",
            'approval_requested' => $ar ? "{$who} طلب اعتماد إكمال {$task}" : "{$who} asks you to approve {$task} as done",
            'approval_decided' => ($d['decision'] ?? '') === 'rejected'
                ? ($ar ? "{$who} أرجع {$task} ولم يعتمد إكمالها" : "{$who} sent {$task} back")
                : ($ar ? "{$who} اعتمد إكمال {$task}" : "{$who} approved {$task} as done"),
            'project_added' => $ar ? "{$who} أضافك إلى مشروع {$project}" : "{$who} added you to {$project}",
            'project_pm' => $ar ? "{$who} سلّم إدارة {$project}" : "{$who} handed {$project} to a new manager",
            'project_plan' => $ar ? "{$who} غيّر خطة {$project}" : "{$who} changed {$project}'s plan",
            'announcement' => $ar ? "{$who} نشر إعلاناً: ".($d['title'] ?? '') : "{$who} posted an announcement: ".($d['title'] ?? ''),
            'bug_reported' => $ar ? "{$who} سجّل مشكلة: ".($d['bug_code'] ?? '') : "{$who} reported a bug: ".($d['bug_code'] ?? ''),
            'bug_assigned' => $ar ? "{$who} حوّل إليك المشكلة ".($d['bug_code'] ?? '') : "{$who} gave you ".($d['bug_code'] ?? ''),
            'bug_status' => $ar ? "{$who} حرّك المشكلة ".($d['bug_code'] ?? '') : "{$who} moved ".($d['bug_code'] ?? ''),
            'bug_late' => $ar ? 'تأخر إصلاح '.($d['bug_code'] ?? '').' عن موعده' : ($d['bug_code'] ?? '').' is past its expected fix day',
            'chat_mention' => $ar ? "{$who} ذكرك في محادثة" : "{$who} mentioned you in a chat",
            'dependency_done' => $ar ? 'أُنجزت '.($d['note'] ?? '-')."، ويمكن متابعة {$task}" : ($d['note'] ?? '-')." is done: {$task} can go on",
            'review_final' => $ar ? "{$who} اعتمد تقييم أدائك" : "{$who} made your performance review final",
            'team_joined' => $ar ? "{$who} أضافك إلى «".($d['note'] ?? '-').'»' : "{$who} put you in \"".($d['note'] ?? '-').'"',
            'account_locked' => $ar ? 'قُفل حساب '.($d['note'] ?? '-').' بعد محاولات دخول خاطئة' : ($d['note'] ?? '-').'\'s account locked after wrong passwords',
            'github_pr_merged' => $ar ? 'دُمج '.($d['note'] ?? '-')." المرتبط بـ {$task}" : ($d['note'] ?? '-')." for {$task} was merged",
            'note_sent' => $ar ? "{$who} أرسل إليك: ".($d['title'] ?? '') : "{$who} sent you: ".($d['title'] ?? ''),
            'note_answered' => $ar ? "{$who} ردّ على: ".($d['title'] ?? '') : "{$who} answered: ".($d['title'] ?? ''),
            'person_rated' => $ar
                ? "{$who} قيّمك تقييماً سريعاً (".($d['score'] ?? '-').' من 5)'
                : "{$who} gave you a quick rating (".($d['score'] ?? '-').' of 5)',
            'team_rated' => $ar
                ? "{$who} قيّم «".($d['team_name'] ?? '-').'» ('.($d['score'] ?? '-').' من 5)'
                : "{$who} rated \"".(($d['team_name_en'] ?? '') ?: ($d['team_name'] ?? '-')).'" ('.($d['score'] ?? '-').' of 5)',
            'birthday_soon', 'birthday_today' => $this->birthdaySentence($n->kind, $d, $ar),
            default => (string) ($d['title'] ?? ($ar ? 'إشعار جديد' : 'New notification')),
        };
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function birthdaySentence(string $kind, array $d, bool $ar): string
    {
        $name = $ar ? (string) ($d['person_name'] ?? '-') : (string) (($d['person_name_en'] ?? '') ?: ($d['person_name'] ?? '-'));
        $due = (string) ($d['due'] ?? '');
        if ($kind === 'birthday_today') {
            return $ar ? "اليوم عيد ميلاد {$name}" : "Today is {$name}'s birthday";
        }
        $dayLabel = $due !== '' ? Birthday::formatDayForLocale($due, $ar ? 'ar' : 'en') : '';

        return $ar ? "عيد ميلاد {$name} بعد أسبوع ({$dayLabel})" : "{$name}'s birthday is in a week ({$dayLabel})";
    }

    private function notificationUrl(Notification $n, User $recipient): string
    {
        $d = is_array($n->data) ? $n->data : [];
        if (in_array($n->kind, ['birthday_soon', 'birthday_today'], true)) {
            $pid = $d['person_id'] ?? null;
            if ($pid && ($recipient->isCeo() || $recipient->role === 'monitor')) {
                return "/performance/{$pid}";
            }

            return '/dashboard#birthdays-title';
        }
        if ($n->task_id) {
            return "/tasks/{$n->task_id}";
        }
        if ($n->announcement_id) {
            return "/announcements/{$n->announcement_id}";
        }
        if ($n->bug_id) {
            return "/bugs/{$n->bug_id}";
        }
        if ($n->chat_key) {
            return "/chat/{$n->chat_key}";
        }
        if ($n->kind === 'review_final') {
            return "/performance/{$n->user_id}";
        }
        if ($n->kind === 'note_sent') {
            $queue = $d['queue'] ?? '';
            if ($queue === 'monitor') {
                return '/monitoring?show=requests';
            }
            if ($queue === 'received') {
                return '/requests?tab=received';
            }

            return '/inbox';
        }
        if ($n->kind === 'note_answered') {
            return ($d['note_kind'] ?? '') === 'request' ? '/requests' : '/monitoring';
        }
        if ($n->kind === 'account_locked') {
            return '/admin';
        }
        if ($n->kind === 'person_rated') {
            return "/performance/{$n->user_id}#ratings";
        }
        if ($n->kind === 'team_rated' && $n->team_id) {
            return "/teams/{$n->team_id}";
        }
        if ($n->project_id) {
            return "/projects/{$n->project_id}";
        }

        return '/notifications';
    }

    public function buildVapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        $subject = trim((string) config('services.vapid.subject', 'mailto:admin@ama8.uk'));
        $pubRaw = self::base64UrlDecode(trim((string) config('services.vapid.public_key', '')));
        $privRaw = self::base64UrlDecode(trim((string) config('services.vapid.private_key', '')));

        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode([
            'aud' => $aud,
            'exp' => time() + 43200,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = "{$header}.{$claims}";
        $pem = self::ecPrivateKeyToPem($privRaw, $pubRaw);

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign VAPID JWT: '.openssl_error_string());
        }

        $rawSig = self::derSignatureToRaw($signature);

        return "{$signingInput}.".self::base64UrlEncode($rawSig);
    }

    public function encryptPayload(string $payload, string $peerPublicB64, string $authB64): string
    {
        $uaPublic = self::base64UrlDecode($peerPublicB64);
        $authSecret = self::base64UrlDecode($authB64);

        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \InvalidArgumentException('Invalid peer P-256 public key.');
        }

        $ephemeral = $this->generateEphemeralEcKey();
        $details = openssl_pkey_get_details($ephemeral);
        if (! is_array($details) || empty($details['ec'])) {
            throw new \RuntimeException('Failed to read ephemeral EC key details.');
        }

        $asPublic = "\x04"
            .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $peerPem = self::ecPublicKeyToPem($uaPublic);
        $peerKey = openssl_pkey_get_public($peerPem);
        if (! $peerKey) {
            throw new \RuntimeException('Failed to load peer public key: '.openssl_error_string());
        }

        $sharedSecret = openssl_pkey_derive($peerKey, $ephemeral, 32);
        if (! is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
            throw new \RuntimeException('ECDH derive failed: '.openssl_error_string());
        }

        $salt = random_bytes(16);

        // RFC 8291 Section 3.3 Key Derivation
        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00".$uaPublic.$asPublic;
        $ikm = substr(hash_hmac('sha256', $keyInfo."\x01", $prkKey, true), 0, 32);

        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload."\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('AES-128-GCM encryption failed.');
        }

        $header = $salt.pack('N', 4096).chr(strlen($asPublic)).$asPublic;

        return $header.$ciphertext.$tag;
    }

    private function generateEphemeralEcKey(): \OpenSSLAsymmetricKey
    {
        $args = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $key = @openssl_pkey_new($args);
        if ($key instanceof \OpenSSLAsymmetricKey) {
            return $key;
        }

        // Windows fallback when OPENSSL_CONF env var is omitted.
        $winConf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($winConf)) {
            $args['config'] = $winConf;
            $key = @openssl_pkey_new($args);
            if ($key instanceof \OpenSSLAsymmetricKey) {
                return $key;
            }
        }

        throw new \RuntimeException('Unable to generate ephemeral P-256 key: '.openssl_error_string());
    }

    public static function ecPublicKeyToPem(string $raw65): string
    {
        // SPKI ASN.1 prefix for uncompressed prime256v1 public key (26 bytes + 65 bytes = 91 bytes total)
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$raw65;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function ecPrivateKeyToPem(string $priv32, string $pub65): string
    {
        $priv32 = str_pad($priv32, 32, "\0", STR_PAD_LEFT);
        $der = "\x30\x77\x02\x01\x01\x04\x20".$priv32
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$pub65;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    public static function derSignatureToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1] ?? "\0") > 0x80) {
            $offset += ord($der[1]) - 0x80;
        }
        $rLen = ord($der[$offset + 1] ?? "\0");
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1] ?? "\0");
        $s = substr($der, $offset + 2, $sLen);

        $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);

        return substr($r, -32).substr($s, -32);
    }

    public static function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $b64u): string
    {
        $padded = strtr($b64u, '-_', '+/');
        $rem = strlen($padded) % 4;
        if ($rem !== 0) {
            $padded .= str_repeat('=', 4 - $rem);
        }

        return (string) base64_decode($padded, true);
    }
}
