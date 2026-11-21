<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(['ar', 'en']);
        $locale = in_array($preferred, ['ar', 'en'], true) ? $preferred : 'ar';

        App::setLocale($locale);

        return $next($request);
    }
}
