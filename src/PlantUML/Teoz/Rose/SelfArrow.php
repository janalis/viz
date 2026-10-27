<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\ArrowHead;

/**
 * A message from a participant to itself, as Rose draws it, 4 down from its top: its label,
 * then a loop 42 out and 13 down — to the right, or to the left when written right to left —
 * its ends shifted by the activations it leaves and comes back to.
 */
final readonly class SelfArrow implements ArrowComponent
{
    private const float DELTA_X = 10.0;
    private const float DELTA_Y = 4.0;
    private const float PADDING_Y = 4.0;
    private const float ARROW_WIDTH = 45.0;
    private const float RIGHT = 42.0;
    private const float LOOP = 13.0;
    private const float CROSS = 6.0;
    private const float CIRCLE = 8.0;
    private const float THIN = 1.5;

    public function __construct(
        private RoseText $text,
        private ArrowConfig $config,
        private Pen $pen,
        private string $back,
        private ArrowHead $head = ArrowHead::Notched,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [\max($this->text->width(), self::ARROW_WIDTH + 5), $this->text->height() + self::DELTA_Y + self::LOOP + (2 * self::PADDING_Y)];
    }

    #[\Override]
    public function yPoint(): float
    {
        return $this->text->height() + (self::LOOP / 2);
    }

    #[\Override]
    public function startY(): float
    {
        return $this->text->height() + self::PADDING_Y;
    }

    /** How far down its arrow comes back. */
    public function endY(): float
    {
        return $this->text->height() + self::LOOP + self::PADDING_Y;
    }

    /** How wide it is drawn: its loop, or its label if wider. */
    public function drawnWidth(): float
    {
        [$width] = $this->text->pure();

        return \max(self::RIGHT, 0.0 === $width ? 0.0 : $this->text->padding[3] + $width);
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer || 'hidden' === $this->config->body) {
            return [];
        }
        $y += self::PADDING_Y;
        $marks = $this->config->reverseDefine ? $this->left($x, $y, $area) : $this->right($x, $y, $area);

        return [...$marks, ...$this->text->marks($x + $this->text->padding[3], $y)];
    }

    /** @return list<Mark> the loop out to the right */
    private function right(float $x, float $y, Area $area): array
    {
        $config = $this->config;
        $top = $y + $this->text->height();
        $bottom = $top + self::LOOP;
        $x1 = $area->deltaX1 < 0 ? $area->deltaX1 : 0.0;
        $x2 = $area->deltaX1 > 0 ? -$area->deltaX1 : 1.0;
        $marks = [];
        if ($config->circle1) {
            $marks[] = $this->circle($x + $x1 + 1 - (self::CIRCLE / 2) - self::THIN, $top);
            $x1 += (self::CIRCLE / 2) + self::THIN + 1 - ('none' === $config->head1 ? self::THIN + 1 : 0.0);
        }
        if ($config->circle2) {
            $marks[] = $this->circle($x + $x2 - (self::CIRCLE / 2) - self::THIN, $bottom);
            $x2 += (self::CIRCLE / 2) + self::THIN;
        }
        $x1 += 'cross' === $config->head1 ? 2 * self::CROSS : 0.0;
        $x2 += 'cross' === $config->head2 ? 2 * self::CROSS : 0.0;
        $async = 'async' === $config->head2 ? 1.0 : 0.0;
        $line = $this->line();
        \array_push(
            $marks,
            $line->line($x + $x1, $top, $x + self::RIGHT, $top),
            $line->line($x + self::RIGHT, $top, $x + self::RIGHT, $bottom),
            $line->line($x + $x2 - $async, $bottom, $x + self::RIGHT, $bottom),
        );
        \array_push($marks, ...match ($config->head1) {
            'cross' => $this->cross($x + self::CROSS, $top),
            'async' => $this->open($x + $x1, $top, self::DELTA_X, self::DELTA_Y),
            'normal' => [$this->filled($x + $x1, $top)],
            default => [],
        });

        return [
            ...$marks,
            ...match ($config->head2) {
                'cross' => $this->cross($x + self::CROSS, $bottom),
                'async' => $this->open($x + $x2, $bottom, self::DELTA_X, -self::DELTA_Y),
                'normal' => [$this->filled($x + $x2, $bottom)],
                default => [],
            },
        ];
    }

    /** @return list<Mark> the loop out to the left, written right to left */
    private function left(float $x, float $y, Area $area): array
    {
        $config = $this->config;
        $top = $y + $this->text->height();
        $bottom = $top + self::LOOP;
        [$width] = $this->size();
        $shift = $area->liveDeltaSize;
        $extra = $area->level * $shift;
        [$x1, $x2] = match (true) {
            $area->deltaX1 < 0 => [0.0, 1.0 + ($area->level > 0 ? -$extra : $shift)],
            $area->deltaX1 > 0 => [$area->level > 1 ? $shift - $extra : 0.0, 1.0 + (1 === $area->level ? -$shift : 0.0)],
            $area->level > 1 => [$shift - $extra, 1.0 + $shift - $extra],
            default => [0.0, 1.0],
        };
        $marks = [];
        if ($config->circle1) {
            $marks[] = $this->circle($x + $width - $x1 + 1 - (self::CIRCLE / 2) - self::THIN, $top);
            $x1 += (self::CIRCLE / 2) - self::THIN + ('none' === $config->head1 ? self::THIN : 0.0);
        }
        if ($config->circle2) {
            $marks[] = $this->circle($x + $width - $x2 + 2 - (self::CIRCLE / 2) - self::THIN, $bottom);
            $x2 += (self::CIRCLE / 2) + self::THIN;
        }
        $crossing = self::CROSS + ($area->level > 0 ? $extra : self::CROSS);
        $x1 += 'cross' === $config->head1 ? $crossing : 0.0;
        $x2 += 'cross' === $config->head2 ? $crossing : 0.0;
        $filled = 'full' === $config->part2 && 'normal' === $config->head2 ? 1.0 : 0.0;
        ++$x1;
        $start = $x + $width - self::RIGHT;
        $line = $this->line();
        \array_push(
            $marks,
            $line->line($start, $top, $start + self::RIGHT - $x1, $top),
            $line->line($start, $top, $start, $bottom),
            $line->line($start, $bottom, $start + self::RIGHT - $x2 - $filled, $bottom),
        );
        $x2 += $filled + ('async' === $config->head2 ? 1.0 : 0.0);
        // Written right to left, the loop leaves without a head, or with a cross.
        if ('cross' === $config->head1) {
            \array_push($marks, ...$this->cross($x + $width - $x1 - (self::CROSS / 2), $top));
        }

        return [
            ...$marks,
            ...match ($config->head2) {
                'cross' => $this->cross($x + $width - $x2 - (self::CROSS / 2), $bottom),
                'async' => $this->open($x + $width - $x2, $bottom, -self::DELTA_X, -self::DELTA_Y),
                'normal' => [$this->filled($x + $width - $x2, $bottom)],
                default => [],
            },
        ];
    }

    /** The loop's line: dotted 2 and 2 for a dotted arrow. */
    private function line(): Pen
    {
        return $this->pen->withDashes('dotted' === $this->config->body ? [2.0, 2.0] : null);
    }

    /** A circle at an end, its left at a point, centred on the line there. */
    private function circle(float $x, float $y): Mark
    {
        return $this->pen
            ->withWidth(self::THIN)
            ->withDashes(null)
            ->ellipse($x, $y - (self::CIRCLE / 2) - (self::THIN / 2), self::CIRCLE, self::CIRCLE, $this->back);
    }

    /** @return list<Mark> a cross, 10 across, its left at a point */
    private function cross(float $x, float $y): array
    {
        $pen = $this->pen->withWidth(2.0)->withDashes(null);

        return [$pen->line($x, $y - 5, $x + 10, $y + 5), $pen->line($x, $y + 5, $x + 10, $y - 5)];
    }

    /**
     * @return list<Mark> an open head's barbs from a point, or one of them for half a head: the
     *                    one a distance down unless only the bottom half is drawn, the other unless only the top
     */
    private function open(float $x, float $y, float $deltaX, float $deltaY): array
    {
        $pen = $this->pen->withDashes(null);
        $part = $this->config->part();
        $marks = [];
        if ('bottom' !== $part) {
            $marks[] = $pen->line($x, $y, $x + $deltaX, $y + $deltaY);
        }
        if ('top' !== $part) {
            $marks[] = $pen->line($x, $y, $x + $deltaX, $y - $deltaY);
        }

        return $marks;
    }

    /** A filled head with its tip at a point, pointing back to the lifeline. */
    private function filled(float $x, float $y): Mark
    {
        $direction = $this->config->reverseDefine ? -1.0 : 1.0;
        $tip = $direction * self::DELTA_X;
        $points = match ($this->config->part()) {
            'top' => [[$tip - 1, -self::DELTA_Y], [-1.0, 0.0], [$tip - 1, 0.0]],
            'bottom' => [[$tip - 1, 0.0], [-1.0, 0.0], [$tip - 1, self::DELTA_Y]],
            default => $this->head->outline([[$tip, -self::DELTA_Y], [0.0, 0.0], [$tip, self::DELTA_Y], [$tip - ($direction * 4), 0.0]]),
        };

        return new Pen($this->pen->color)->shape(\array_map(static fn (array $point): array => [$x + $point[0], $y + $point[1]], $points), $this->pen->color);
    }
}
