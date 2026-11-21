<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnforceReadOnly;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SetLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'read.only' => EnforceReadOnly::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ApiException $e, Request $request) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->status, $e->errors);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$field] = is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages;
            }
            $first = reset($errors);

            return ApiResponse::error(
                is_string($first) && $first !== '' ? $first : __('api.validation_failed'),
                'VALIDATION',
                422,
                $errors
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiResponse::error(__('api.unauthenticated'), 'UNAUTHENTICATED', 401);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return ApiResponse::error(__('api.forbidden'), 'FORBIDDEN', 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return ApiResponse::error(__('api.not_found'), 'NOT_FOUND', 404);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return ApiResponse::error(__('api.rate_limited'), 'RATE_LIMITED', 429);
        });
    })->create();
