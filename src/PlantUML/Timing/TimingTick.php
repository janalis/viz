<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/** PlantUML's `TimeTick`: a moment, as a number, and how the time axis writes it — a number, `h:mm:ss`, or `mm/dd`. */
final readonly class TimingTick
{
    public function __construct(
        public float $time,
        public string $format = 'decimal',
    ) {}

    /** As PlantUML's `TimingFormat.formatTime`, a whole number of seconds for an hour or a date. */
    public static function written(float $time, string $format): string
    {
        $seconds = (int) $time;

        return match ($format) {
            'hour' => \sprintf('%d:%02d:%02d', \intdiv($seconds, num2: 3600), \intdiv($seconds, num2: 60) % 60, $seconds % 60),
            'date' => \gmdate('m/d', $seconds),
            default => self::plain($time),
        };
    }

    /** A number as Java's `BigDecimal.toPlainString` writes the integers and short decimals the commands give. */
    public static function plain(float $time): string
    {
        return \floor($time) === $time && \abs($time) < 1e15
            ? (string) (int) $time
            : \rtrim(\rtrim(\number_format($time, decimals: 6, decimal_separator: '.', thousands_separator: ''), characters: '0'), characters: '.');
    }
}
