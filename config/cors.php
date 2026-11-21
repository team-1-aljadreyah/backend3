<?php

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_ALLOWED_ORIGINS', 'https://follow.ama8.uk'))
)));

$rawPatterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_ALLOWED_ORIGIN_PATTERNS', ''))
)));

$allowedPatterns = array_map(function (string $pattern): string {
    $first = $pattern[0] ?? '';
    if ($first === '/' || $first === '#') {
        return $pattern;
    }

    return '#'.str_replace('#', '\\#', $pattern).'#';
}, $rawPatterns);

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedPatterns,

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'Accept-Language', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
