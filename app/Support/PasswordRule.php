<?php

namespace App\Support;

use App\Exceptions\ApiException;

final class PasswordRule
{
    public const MIN_DEFAULT = 8;

    public const MAX = 128;

    /**
     * @return array{key: string, params: array<string, int|string>}|null
     */
    public static function problem(string $password, int $min = self::MIN_DEFAULT): ?array
    {
        if ($password === '') {
            return ['key' => 'api.password.required', 'params' => []];
        }
        if (mb_strlen($password) < $min) {
            return ['key' => 'api.password.too_short', 'params' => ['min' => $min]];
        }
        if (mb_strlen($password) > self::MAX) {
            return ['key' => 'api.password.too_long', 'params' => []];
        }
        if (trim($password) === '') {
            return ['key' => 'api.password.spaces_only', 'params' => []];
        }

        return null;
    }

    public static function checkOrFail(string $password, int $min = self::MIN_DEFAULT, string $field = 'password'): void
    {
        $p = self::problem($password, $min);
        if ($p !== null) {
            throw ApiException::validation($field, $p['key'], $p['params']);
        }
    }
}
