<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->active) {
            throw new ApiException(401, 'UNAUTHENTICATED', __('api.unauthenticated'));
        }

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! empty($token->session_id)) {
            $session = AuthSession::query()->find($token->session_id);
            if ($session) {
                $now = now('UTC');
                if (! $session->isActive($now)) {
                    $token->delete();
                    throw new ApiException(401, 'UNAUTHENTICATED', __('api.unauthenticated'));
                }

                // Throttle session activity updates to at most once per minute.
                if ($session->last_seen_at === null || $session->last_seen_at->lt($now->copy()->subMinute())) {
                    $session->last_seen_at = $now;
                    $session->save();
                }
            }
        }

        // Remember user's last UI language for Web Push notifications.
        $locale = app()->getLocale();
        if (in_array($locale, ['ar', 'en'], true) && $user->preferred_locale !== $locale) {
            $user->forceFill(['preferred_locale' => $locale])->saveQuietly();
        }

        return $next($request);
    }
}
