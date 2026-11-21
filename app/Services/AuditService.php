<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\ClientContext;

class AuditService
{
    /**
     * @param  array{type: string, id?: int|null, label: string}  $target
     * @param  array<int, array{field: string, from?: string|null, to?: string|null}>|null  $changes
     */
    public function log(?int $actorId, string $action, array $target, ?array $changes = null): AuditLog
    {
        $cleaned = null;
        if ($changes !== null && $changes !== []) {
            $cleaned = array_values(array_map(function (array $c) {
                $entry = ['field' => $c['field']];
                if (array_key_exists('from', $c) && $c['from'] !== null) {
                    $entry['from'] = (string) $c['from'];
                }
                if (array_key_exists('to', $c) && $c['to'] !== null) {
                    $entry['to'] = (string) $c['to'];
                }

                return $entry;
            }, $changes));
        }

        return AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $target['type'],
            'target_id' => $target['id'] ?? null,
            'target_label' => $target['label'],
            'changes' => $cleaned,
            'at' => now('UTC'),
            'ip' => ClientContext::clientIp(),
            'user_agent' => ClientContext::userAgent(),
            'session_id' => $actorId === null ? null : ClientContext::sessionId(),
            'request_id' => ClientContext::requestId(),
        ]);
    }
}
