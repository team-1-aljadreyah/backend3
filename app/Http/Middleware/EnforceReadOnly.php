<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AdvancedSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceReadOnly
{
    public function __construct(private readonly AdvancedSettingsService $advanced) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || $user->isCeo()) {
            return $next($request);
        }

        if ($this->isOwnBookkeepingRoute($request)) {
            return $next($request);
        }

        $ro = $this->advanced->readOnlyStatus();
        if ($ro === null) {
            return $next($request);
        }

        $isAr = app()->getLocale() === 'ar';
        $prefix = __('api.advanced.read_only');
        $note = trim((string) ($isAr ? ($ro['message'] ?? '') : (($ro['message_en'] ?? '') ?: ($ro['message'] ?? ''))));
        $fullMessage = $note !== '' ? "{$prefix} {$note}" : $prefix;

        throw new ApiException(423, 'READ_ONLY', $fullMessage);
    }

    private function isOwnBookkeepingRoute(Request $request): bool
    {
        return $request->is(
            'api/v1/auth/logout',
            'api/v1/notifications/*/read',
            'api/v1/notifications/read-all',
            'api/v1/notifications/preferences',
            'api/v1/chats/*/read',
            'api/v1/announcements/*/seen',
            'api/v1/me/avatar',
            'api/v1/me/push-subscriptions'
        );
    }
}
