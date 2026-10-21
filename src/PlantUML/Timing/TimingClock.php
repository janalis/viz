<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * PlantUML's `PanelsClock`: a square wave 8 in from the top and bottom of its 30 — or as
 * asked — low until its offset, then high for its pulse, half its period by default, and low
 * for the rest, until past the time axis.
 */
final class TimingClock implements TimingPanel
{
    private const float MARGIN_Y = 8.0;

    private Style $style;

    public function __construct(
        private readonly TimingPlayer $player,
        private readonly TimingParts $parts,
    ) {
        $this->style = $parts->sheet->style(['root', 'element', 'timingDiagram', 'clock'], $player->stereotypes);
    }

    #[\Override]
    public function leftWidth(): float
    {
        return 5.0;
    }

    #[\Override]
    public function height(): float
    {
        return (float) $this->player->height;
    }

    #[\Override]
    public function left(float $x, float $y, float $width): array
    {
        return [];
    }

    #[\Override]
    public function right(float $x, float $y): array
    {
        $ruler = $this->parts->ruler;
        $line = $this->player->height - (2 * self::MARGIN_Y);
        [$high, $low] = [$y + self::MARGIN_Y, $y + self::MARGIN_Y + $line];
        $marks = [];
        $value = 0.0;
        if (0.0 !== $this->player->offset) {
            $marks[] = $this->across($x, $low, $value, $this->player->offset);
            $value += $this->player->offset;
        }
        if ($ruler->x($value) > $ruler->width()) {
            return $marks;
        }
        $marks[] = $this->up($x, $high, $low, $value);
        $pulse = $this->pulse();
        $rest = $this->player->period - $pulse;
        for ($wave = 0; $wave < 1000; ++$wave) {
            $marks[] = $this->across($x, $high, $value, $value + $pulse);
            $value += $pulse;
            if ($ruler->x($value) > $ruler->width()) {
                break;
            }
            $marks[] = $this->up($x, $high, $low, $value);
            $marks[] = $this->across($x, $low, $value, $value + $rest);
            $value += $rest;
            if ($ruler->x($value) > $ruler->width()) {
                break;
            }
            $marks[] = $this->up($x, $high, $low, $value);
        }

        return $marks;
    }

    #[\Override]
    public function projection(TimingTick $tick): ?array
    {
        $point = [$this->parts->ruler->x($tick->time), $this->highAt($tick->time) ? self::MARGIN_Y : $this->player->height - self::MARGIN_Y];

        return [$point, $point];
    }

    private function pulse(): float
    {
        return 0.0 === $this->player->pulse ? $this->player->period / 2 : $this->player->pulse;
    }

    private function highAt(float $time): bool
    {
        $period = $this->player->period;
        if ($period <= 0 || $time < $this->player->offset) {
            return false;
        }
        $phase = \fmod($time - $this->player->offset, $period);

        return ($phase < 0 ? $phase + $period : $phase) < $this->pulse();
    }

    /** A line along the wave between two moments, cut at the time axis's end. */
    private function across(float $x, float $y, float $from, float $to): Mark
    {
        $ruler = $this->parts->ruler;

        return $this->line($x + $ruler->x($from), $y, $x + \min($ruler->width(), $ruler->x($to)), $y);
    }

    private function up(float $x, float $high, float $low, float $at): Mark
    {
        return $this->line($x + $this->parts->ruler->x($at), $high, $x + $this->parts->ruler->x($at), $low);
    }

    private function line(float $x1, float $y1, float $x2, float $y2): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $this->style->color('LineColor', '#006400'), strokeWidth: $this->style->number('LineThickness', 1.5));
    }
}
