<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public static function error(string $message, string $code, int $status, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Remove null values for optional TypeScript fields while preserving explicitly nullable keys.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string[]  $keepNullKeys
     * @return array<string, mixed>
     */
    public static function clean(array $attributes, array $keepNullKeys = []): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if ($value === null && ! in_array($key, $keepNullKeys, true)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
