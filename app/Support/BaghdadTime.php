<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BaghdadTime
{
    public static function timezone(): string
    {
        if (function_exists('app') && app()->bound('config')) {
            return (string) config('app.working_timezone', 'Asia/Baghdad');
        }

        return 'Asia/Baghdad';
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    public static function toBaghdadDate(CarbonInterface|string $value): string
    {
        if (is_string($value)) {
            $value = CarbonImmutable::parse($value);
        }

        return $value->setTimezone(self::timezone())->toDateString();
    }

    public static function iso(?CarbonInterface $dt): ?string
    {
        return $dt?->utc()->toIso8601ZuluString();
    }

    public static function daysBetween(string $fromDay, string $toDay): int
    {
        $a = CarbonImmutable::createFromFormat('!Y-m-d', $fromDay, 'UTC');
        $b = CarbonImmutable::createFromFormat('!Y-m-d', $toDay, 'UTC');

        return (int) $a->diffInDays($b, false);
    }

    public static function plannedRatio(string $start, string $end, string $today): float
    {
        $length = self::daysBetween($start, $end);
        if ($length <= 0) {
            return 1.0;
        }

        $elapsed = self::daysBetween($start, $today);

        return min(1.0, max(0.0, $elapsed / $length));
    }
}
