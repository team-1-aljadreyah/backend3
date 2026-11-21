<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->active) {
            throw new ApiException(401, 'UNAUTHENTICATED', __('api.unauthenticated'));
        }

        return $next($request);
    }
}
