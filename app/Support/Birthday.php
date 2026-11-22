<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class Birthday
{
    public const SOON_DAYS = 7;

    public const MIN_AGE = 16;

    public const MAX_AGE = 80;

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }

    public static function monthDay(string $birthDate): string
    {
        return substr($birthDate, -5);
    }

    public static function birthdayInYear(string $birthDate, int $year): string
    {
        $md = self::monthDay($birthDate);
        if ($md === '02-29' && ! self::isLeapYear($year)) {
            return sprintf('%04d-02-28', $year);
        }

        return sprintf('%04d-%s', $year, $md);
    }

    /**
     * @return array{date: string, days: int}
     */
    public static function nextBirthday(string $birthDate, string $day): array
    {
        $year = (int) substr($day, 0, 4);
        $date = self::birthdayInYear($birthDate, $year);
        if ($date < $day) {
            $date = self::birthdayInYear($birthDate, $year + 1);
        }

        return [
            'date' => $date,
            'days' => BaghdadTime::daysBetween($day, $date),
        ];
    }

    /**
     * @return array{kind: 'birthday_today'|'birthday_soon', date: string}|null
     */
    public static function reminderForDay(string $birthDate, string $day): ?array
    {
        $next = self::nextBirthday($birthDate, $day);
        if ($next['days'] === 0) {
            return ['kind' => 'birthday_today', 'date' => $next['date']];
        }
        if ($next['days'] === self::SOON_DAYS) {
            return ['kind' => 'birthday_soon', 'date' => $next['date']];
        }

        return null;
    }

    public static function isRealDay(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    public static function ageOn(string $birthDate, string $day): int
    {
        $years = (int) substr($day, 0, 4) - (int) substr($birthDate, 0, 4);

        return substr($day, 5) < substr($birthDate, 5) ? $years - 1 : $years;
    }

    /**
     * @return 'date'|'future'|'young'|'old'|null
     */
    public static function problem(mixed $value, string $today): ?string
    {
        if (! self::isRealDay($value)) {
            return 'date';
        }
        /** @var string $value */
        if ($value > $today) {
            return 'future';
        }
        $age = self::ageOn($value, $today);
        if ($age < self::MIN_AGE) {
            return 'young';
        }
        if ($age > self::MAX_AGE) {
            return 'old';
        }

        return null;
    }

    public static function formatDayForLocale(string $day, string $locale): string
    {
        $dt = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');
        if ($locale === 'ar') {
            $months = [
                1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان',
                5 => 'أيار', 6 => 'حزيران', 7 => 'تموز', 8 => 'آب',
                9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
            ];

            return $dt->day.' '.$months[$dt->month];
        }

        return $dt->format('M j');
    }
}
