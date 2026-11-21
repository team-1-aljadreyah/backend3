<?php

namespace App\Support;

final class SearchNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = mb_strtolower(trim($text), 'UTF-8');
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);

        return (string) preg_replace('/[ًٌٍَُِّْ]/u', '', $s);
    }

    /**
     * @param  string|null  ...$fields
     */
    public static function matches(string $query, ?string ...$fields): bool
    {
        $needle = self::normalize($query);
        if ($needle === '') {
            return true;
        }

        foreach ($fields as $field) {
            if ($field !== null && $field !== '' && str_contains(self::normalize($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function ipMatches(?string $ip, ?string $filter): bool
    {
        $f = trim((string) ($filter ?? ''));
        if ($f === '') {
            return true;
        }
        if ($ip === null || $ip === '') {
            return false;
        }
        if (str_ends_with($f, '*')) {
            return str_starts_with($ip, substr($f, 0, -1));
        }
        if (str_ends_with($f, '.') || str_ends_with($f, ':')) {
            return str_starts_with($ip, $f);
        }

        return $ip === $f || str_starts_with($ip, "{$f}.") || str_starts_with($ip, "{$f}:");
    }
}
