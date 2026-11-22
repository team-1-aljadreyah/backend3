<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $subscriptionId,
        public readonly array $payload,
        public readonly string $urgency = 'normal',
        public readonly int $ttl = 86400,
    ) {}

    public function handle(PushService $push): void
    {
        $sub = PushSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            return;
        }

        $result = $push->sendToSubscription($sub, $this->payload, $this->urgency, $this->ttl);
        if ($result === 'retry' && $this->attempts() < $this->tries) {
            $this->release(60);
        }
    }
}
