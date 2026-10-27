<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\ArrowHead;

/**
 * A message's arrow across its area, as Rose draws it, 4 down from its top: its label over
 * the line, the line stopping short of its heads, a filled, open or crossed head or a circle
 * at either end, and both slanted by the arrow's inclination.
 */
final readonly class MessageArrow implements Component, ArrowComponent
{
    private const float DELTA_X = 10.0;
    private const float DELTA_Y = 4.0;
    private const float PADDING_Y = 4.0;
    private const float CROSS = 6.0;
    private const float CIRCLE = 8.0;
    private const float THIN = 1.5;

    public function __construct(
        private RoseText $text,
        private ArrowConfig $config,
        private Pen $pen,
        private string $back,
        /** Where the label stands along the arrow: `left`, `center` or `right`. */
        private string $align = 'left',
        private ArrowHead $head = ArrowHead::Notched,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [
            $this->text->width() + self::DELTA_X,
            $this->text->height() + self::DELTA_Y + (2 * self::PADDING_Y) + $this->config->inclination1() + $this->config->inclination2(),
        ];
    }

    #[\Override]
    public function yPoint(): float
    {
        return $this->text->height() + self::PADDING_Y;
    }

    #[\Override]
    public function startY(): float
    {
        return $this->yPoint() + $this->config->inclination2();
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer || 'hidden' === $this->config->body) {
            return [];
        }
        $y += self::PADDING_Y;
        $config = $this->config;
        $start = 0.0;
        $length = $area->width - 1;
        if ($config->circle2) {
            $length -= 'none' === $config->head2 ? self::CIRCLE / 2 : (self::CIRCLE / 2) + self::THIN;
        }
        if ($config->circle1 && 'cross' !== $config->head1) {
            $shift = 'none' === $config->head1 ? self::CIRCLE / 2 : (self::CIRCLE / 2) + self::THIN;
            $start += $shift;
            $length -= $shift;
        }
        if ('full' === $config->part2 && 'normal' === $config->head2) {
            $length -= self::DELTA_X / 2;
        }
        if ('full' === $config->part1 && 'normal' === $config->head1) {
            $start += self::DELTA_X / 2;
            $length -= self::DELTA_X / 2;
        }
        if ('cross' === $config->head2) {
            $length -= 2 * self::CROSS;
        }
        if ('cross' === $config->head1) {
            $start += 2 * self::CROSS;
            $length -= 2 * self::CROSS;
        }
        $arrowY = $y + $this->text->height();
        $first = $x + 1;
        $last = $x + $area->width - 2;
        $inclination1 = $config->inclination1();
        $inclination2 = $config->inclination2();
        $line = $this->pen->withDashes($this->pen->dashes ?? ('dotted' === $config->body ? [2.0, 2.0] : null));
        $marks = [
            ...$this->head1($first, $arrowY + $inclination1, $area->width),
            ...$this->head2($last, $arrowY + $inclination2, $area->width),
            match (true) {
                0 !== $inclination1 => $line->line($x + $start + $length, $arrowY, $x, $arrowY + $inclination1),
                0 !== $inclination2 => $line->line($x + $start, $arrowY, $last, $arrowY + $inclination2),
                default => $line->line($x + $start, $arrowY, $x + $start + $length, $arrowY),
            },
        ];
        [$textWidth] = $this->text->pure();
        $direction = $config->direction();
        $textX = match ($this->align) {
            'center' => ($area->width - \abs($area->textDeltaX) - $textWidth) / 2,
            'right' => $area->width - \abs($area->textDeltaX) - $textWidth - $this->text->padding[1] - ('normal' === $direction ? self::DELTA_X : 0.0),
            default => $this->text->padding[3] + (\in_array($direction, ['reverse', 'both'], strict: true) ? self::DELTA_X : 0.0),
        };

        return [...$marks, ...$this->text->marks($x + $textX + \max(0.0, $area->textDeltaX), $y)];
    }

    /** @return list<Mark> the head or circle at the arrow's first end, pointing left */
    private function head1(float $x, float $y, float $length): array
    {
        $marks = [];
        $config = $this->config;
        if ($config->circle1) {
            $marks[] = $this->circle($x - (self::CIRCLE / 2) - self::THIN, $y - (self::CIRCLE / 2) - (self::THIN / 2));
            $x += 'cross' === $config->head1 ? 0.0 : (self::CIRCLE / 2) + self::THIN;
        }
        $angle = \atan2(-$config->inclination1(), $length);

        return [
            ...$marks,
            ...match ($config->head1) {
                'async' => $this->open($x, $y, $config->part1, self::DELTA_X, $angle),
                'cross' => $this->cross($x + self::CROSS, $y),
                'normal' => [$this->filled($x, $y, $config->part1, self::DELTA_X, $angle)],
                default => [],
            },
        ];
    }

    /** @return list<Mark> the head or circle at the arrow's last end, pointing right */
    private function head2(float $x, float $y, float $length): array
    {
        $marks = [];
        $config = $this->config;
        if ($config->circle2) {
            $marks[] = $this->circle($x - (self::CIRCLE / 2) + self::THIN, $y - (self::CIRCLE / 2) - (self::THIN / 2));
            $x -= (self::CIRCLE / 2) + self::THIN;
        }
        $angle = \atan2($config->inclination2(), $length);

        return [
            ...$marks,
            ...match ($config->head2) {
                'async' => $this->open($x, $y, $config->part2, -self::DELTA_X, $angle),
                'cross' => $this->cross($x - self::CROSS - self::DELTA_X, $y),
                'normal' => [$this->filled($x, $y, $config->part2, -self::DELTA_X, $angle)],
                default => [],
            },
        ];
    }

    private function circle(float $x, float $y): Mark
    {
        return $this->pen->withWidth(self::THIN)->withDashes(null)->ellipse($x, $y, self::CIRCLE, self::CIRCLE, $this->back);
    }

    /** @return list<Mark> an open head: its two barbs, or one for half a head */
    private function open(float $x, float $y, string $part, float $deltaX, float $angle): array
    {
        $pen = $this->pen->withDashes(null);
        $marks = [];
        foreach (['top' => -self::DELTA_Y, 'bottom' => self::DELTA_Y] as $half => $deltaY) {
            if ($part === ('top' === $half ? 'bottom' : 'top')) {
                continue;
            }
            [$dx, $dy] = self::rotated($deltaX, $deltaY, $angle);
            $marks[] = $pen->line($x, $y, $x + $dx, $y + $dy);
        }

        return $marks;
    }

    /** @return list<Mark> a cross, 10 across, its left at a point */
    private function cross(float $x, float $y): array
    {
        $pen = $this->pen->withWidth(2.0)->withDashes(null);

        return [$pen->line($x, $y - 5, $x + 10, $y + 5), $pen->line($x, $y + 5, $x + 10, $y - 5)];
    }

    /** A filled head, its tip at a point, its barbs a distance along: the whole of it notched, or one half. */
    private function filled(float $x, float $y, string $part, float $deltaX, float $angle): Mark
    {
        $points = match ($part) {
            'top' => [[$deltaX, -self::DELTA_Y], [0.0, 0.0], [$deltaX, 0.0]],
            'bottom' => [[$deltaX, 0.0], [0.0, 0.0], [$deltaX, self::DELTA_Y]],
            default => $this->head->outline([[$deltaX, -self::DELTA_Y], [0.0, 0.0], [$deltaX, self::DELTA_Y], [$deltaX - (4 * ($deltaX <=> 0)), 0.0]]),
        };

        return new Pen($this->pen->color)->shape(\array_map(static function (array $point) use ($x, $y, $angle): array {
            [$dx, $dy] = self::rotated($point[0], $point[1], $angle);

            return [$x + $dx, $y + $dy];
        }, $points), $this->pen->color);
    }

    /** @return array{float, float} a vector turned by an angle */
    private static function rotated(float $x, float $y, float $angle): array
    {
        return [($x * \cos($angle)) - ($y * \sin($angle)), ($x * \sin($angle)) + ($y * \cos($angle))];
    }
}
