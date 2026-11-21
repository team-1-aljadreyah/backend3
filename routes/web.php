<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', function () {
    abort_unless(config('app.swagger_enabled'), 404);

    return view('swagger');
});

Route::get('/docs/openapi.yaml', function () {
    abort_unless(config('app.swagger_enabled'), 404);

    $path = resource_path('docs/openapi.yaml');
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
});
