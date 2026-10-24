<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

/**
 * Gantt time as PlantUML's `TimePoint` keeps it, in whole seconds since 1970 in UTC; a day
 * as the number of days since then.
 */
final class GanttTime
{
    public const int DAY = 86_400;

    /** The day a moment falls on. */
    public static function day(int $seconds): int
    {
        return \intdiv($seconds - ((($seconds % self::DAY) + self::DAY) % self::DAY), self::DAY);
    }

    public static function start(int $day): int
    {
        return $day * self::DAY;
    }

    /** A day's weekday, 1 for Monday to 7 for Sunday. */
    public static function weekday(int $day): int
    {
        return (((($day + 3) % 7) + 7) % 7) + 1;
    }

    public static function of(int $year, int $month, int $day): int
    {
        return \intdiv((int) \gmmktime(hour: 12, minute: 0, second: 0, month: $month, day: $day, year: $year), self::DAY);
    }

    /** @return array{int, int, int} a day's year, month and day of the month */
    public static function date(int $day): array
    {
        $date = \explode('-', \gmdate('Y-n-j', self::start($day)));

        return [(int) $date[0], (int) $date[1], (int) $date[2]];
    }

    /** Whether a moment is a day's first. */
    public static function midnight(int $seconds): bool
    {
        return 0 === ((($seconds % self::DAY) + self::DAY) % self::DAY);
    }

    public static function monthShort(int $month): string
    {
        return \substr(self::monthLong($month), offset: 0, length: 3);
    }

    public static function monthLong(int $month): string
    {
        return ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][$month - 1] ?? '';
    }

    public static function weekdayShort(int $weekday): string
    {
        return \substr(self::weekdayLong($weekday), offset: 0, length: 2);
    }

    public static function weekdayLong(int $weekday): string
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$weekday - 1] ?? '';
    }

    /** A weekday by its name's first three letters, as PlantUML's `DayOfWeekUtils.fromString`. */
    public static function weekdayNamed(string $name): int
    {
        $index = \array_search(\strtolower(\substr($name, offset: 0, length: 3)), ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], strict: true);

        return false === $index ? 1 : $index + 1;
    }

    /** A month by its name's first three letters, as PlantUML's `MonthUtils.fromString`; January for any other. */
    public static function monthNamed(string $name): int
    {
        return (
            ['feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12][\strtolower(\substr($name, offset: 0, length: 3))]
            ?? 1
        );
    }
}
