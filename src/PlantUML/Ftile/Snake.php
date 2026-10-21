<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\Source\Unsupported;

/**
 * An arrow of an activity diagram, as PlantUML's snake draws it: its worm of straight steps,
 * a head at its end, an arrowhead midway along the first step going a way it emphasises, and
 * its labels. Arrows meeting end to start merge before they are drawn, and an arrow ending
 * where another starts loses its head.
 */
final readonly class Snake
{
    /**
     * @param ?list<array{float, float}> $head the head's outline about its tip
     * @param list<array{TextBlock, ?string, ?string}> $labels each label with its vertical alignment, `bottom` or `center`, and its horizontal one
     */
    public function __construct(
        public Worm $worm,
        public Rainbow $color,
        public ?array $head = null,
        public MergeStrategy $merge = MergeStrategy::Full,
        /** `U`, `D`, `L` or `R`: a step going that way gets an arrowhead midway. */
        public ?string $emphasize = null,
        public array $labels = [],
    ) {}

    /**
     * An arrow through points.
     *
     * @param ?list<array{float, float}> $head
     * @param list<array{float, float}> $points
     */
    public static function through(Rainbow $color, ?array $head, array $points): self
    {
        $worm = new Worm();
        foreach ($points as [$x, $y]) {
            $worm->add($x, $y);
        }

        return new self($worm, $color, $head);
    }

    /**
     * The same arrow through other points.
     *
     * @param list<array{float, float}> $points
     */
    public function along(array $points): self
    {
        return new self(self::through($this->color, $this->head, $points)->worm, $this->color, $this->head, $this->merge, $this->emphasize, $this->labels);
    }

    public function withMerge(MergeStrategy $merge): self
    {
        return new self($this->worm, $this->color, $this->head, $merge, $this->emphasize, $this->labels);
    }

    public function emphasizing(string $direction): self
    {
        return new self($this->worm, $this->color, $this->head, $this->merge, $direction, $this->labels);
    }

    /** With a label, unless none is given. */
    public function withLabel(?TextBlock $label, ?string $vertical = null): self
    {
        if (null === $label) {
            return $this;
        }

        return new self($this->worm, $this->color, $this->head, $this->merge, $this->emphasize, [...$this->labels, [$label, $vertical, null]]);
    }

    public function withoutHead(): self
    {
        return new self($this->worm, $this->color, null, $this->merge, $this->emphasize, $this->labels);
    }

    public function moved(float $dx, float $dy): self
    {
        return new self($this->worm->moved($dx, $dy), $this->color, $this->head, $this->merge, $this->emphasize, $this->labels);
    }

    /** Whether no arrow may lose its head for ending where it starts: it merges less, or is one stretch across. */
    public function untouchable(): bool
    {
        return MergeStrategy::Full !== $this->merge || $this->worm->horizontal();
    }

    /** It and another meeting it end to start, as one arrow, or null when they do not merge. */
    public function merge(self $other): ?self
    {
        $strategy = $this->merge->max($other->merge);
        if (MergeStrategy::None === $strategy || [] !== \array_filter($other->labels, static fn (array $label): bool => $label[0]->size()[0] > 0)) {
            return null;
        }
        if (self::same($this->worm->last(), $other->worm->first())) {
            return new self($this->worm->merge($other->worm, $strategy), $this->color, $other->head ?? $this->head, $strategy, $this->emphasize ?? $other->emphasize, [
                ...$this->labels,
                ...$other->labels,
            ]);
        }
        if (self::same($this->worm->first(), $other->worm->last())) {
            return $other->merge($this);
        }

        return null;
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    public static function same(array $a, array $b): bool
    {
        return \hypot($a[0] - $b[0], $a[1] - $b[1]) < 0.001;
    }

    /**
     * Its lines, its heads and its labels.
     *
     * @return list<Shape>
     */
    public function shapes(Pen $normal): array
    {
        if (\count($this->color->stripes) > 1) {
            return $this->rainbow($normal);
        }
        $shapes = $this->line($this->worm, $this->color, $normal, 1.5);
        foreach ($this->labels as $label) {
            [$x, $y] = $this->labelAt($label);
            \array_push($shapes, ...$label[0]->shapes($x, $y));
        }

        return $shapes;
    }

    /**
     * Each colour of an arrow of several beside the one before, as PlantUML draws them, its
     * labels moved past them.
     *
     * @return list<Shape>
     * @throws Unsupported for an arrow turning in a way PlantUML has no moves for
     */
    private function rainbow(Pen $normal): array
    {
        $stripes = $this->color->stripes;
        $mutation = WormMutation::of($this->worm, 2 + $this->color->separation);
        if ($mutation->negative()) {
            $stripes = \array_reverse($stripes);
        }
        $global = (-1.0 * (\count($stripes) - 1)) / 2.0;
        $current = self::moveFirst($this->worm, $mutation->moves[0], $global);
        if (\count($mutation->moves) > 2) {
            $current = self::moveLast($current, $mutation->moves[\count($mutation->moves) - 1], $global);
        }
        $shapes = [];
        foreach ($stripes as $index => $stripe) {
            $stroke = match (true) {
                0.0 !== $this->color->separation => 1.5,
                (\count($stripes) - 1) === $index => 2.0,
                default => 3.0,
            };
            \array_push($shapes, ...$this->line($current, $stripe, $normal, $stroke));
            $current = $mutation->mute($current);
        }
        $shift = $mutation->textShift(\count($stripes));
        foreach ($this->labels as $label) {
            [$x, $y] = $this->labelAt($label);
            \array_push($shapes, ...$label[0]->shapes($x + $shift, $y));
        }

        return $shapes;
    }

    /**
     * A worm's line in one colour, its arrowhead midway and its head; nothing when hidden.
     *
     * @return list<Shape>
     */
    private function line(Worm $worm, Rainbow $color, Pen $normal, float $stroke): array
    {
        if ('hidden' === $color->style) {
            return [];
        }
        $pen = match ($color->style) {
            'dashed' => new Pen($color->color, $stroke, [7.0, 7.0]),
            'dotted' => new Pen($color->color, $stroke, [1.0, 3.0]),
            'bold' => new Pen($color->color, 2.0),
            default => $normal->withColor($color->color),
        };
        $shapes = [Shape::of(ShapeKind::Line, [$pen->path($worm->points)]), ...$this->emphasis($worm, $pen, $color->color)];
        if (null !== $this->head) {
            $shapes[] = self::head($this->head, $worm->last(), $color->head);
        }

        return $shapes;
    }

    /**
     * A worm with its first point moved along a move across or down, scaled, the second with it when level.
     *
     * @param array{float, float} $move
     */
    private static function moveFirst(Worm $worm, array $move, float $scale): Worm
    {
        [$dx, $dy] = [$move[0] * $scale, $move[1] * $scale];
        $points = $worm->points;
        [$x0, $y0] = $points[0];
        [$x1, $y1] = $points[1];
        $points[1] = [0.0 !== $dx && $x0 === $x1 ? $x1 + $dx : $x1, 0.0 !== $dy && $y0 === $y1 ? $y1 + $dy : $y1];
        $points[0] = [$x0 + $dx, $y0 + $dy];
        $result = new Worm();
        $result->points = $points;

        return $result;
    }

    /**
     * A worm with its last point moved along a move across or down, scaled, the one before with it when level.
     *
     * @param array{float, float} $move
     */
    private static function moveLast(Worm $worm, array $move, float $scale): Worm
    {
        [$dx, $dy] = [$move[0] * $scale, $move[1] * $scale];
        $points = $worm->points;
        $last = \count($points) - 1;
        [$x8, $y8] = $points[$last - 1];
        [$x9, $y9] = $points[$last];
        $points[$last - 1] = [0.0 !== $dx && $x8 === $x9 ? $x8 + $dx : $x8, 0.0 !== $dy && $y8 === $y9 ? $y8 + $dy : $y8];
        $points[$last] = [$x9 + $dx, $y9 + $dy];
        $result = new Worm();
        $result->points = $points;

        return $result;
    }

    /** The rightmost point it reaches, its labels included. */
    public function maxX(): float
    {
        $max = \max(\array_column($this->worm->points, 0));
        foreach ($this->labels as $label) {
            $max = \max($max, $this->labelAt($label)[0] + $label[0]->size()[0]);
        }

        return $max;
    }

    /**
     * An arrowhead's outline pointing a way, its tip at the origin.
     *
     * @return list<array{float, float}>
     */
    public static function arrow(string $direction): array
    {
        return match ($direction) {
            'U' => [[-4.0, 10.0], [0.0, 0.0], [4.0, 10.0], [0.0, 6.0]],
            'D' => [[-4.0, -10.0], [0.0, 0.0], [4.0, -10.0], [0.0, -6.0]],
            'R' => [[-10.0, -4.0], [0.0, 0.0], [-10.0, 4.0], [-6.0, 0.0]],
            default => [[10.0, -4.0], [0.0, 0.0], [10.0, 4.0], [6.0, 0.0]],
        };
    }

    /** @return list<Shape> the arrowhead midway along the first step going the way it emphasises */
    private function emphasis(Worm $worm, Pen $pen, string $color): array
    {
        if (null === $this->emphasize) {
            return [];
        }
        $points = $worm->points;
        for ($i = 0, $count = \count($points); $i < ($count - 1); ++$i) {
            if (Worm::direction($points[$i], $points[$i + 1]) !== $this->emphasize) {
                continue;
            }
            $middle = [($points[$i][0] + $points[$i + 1][0]) / 2, ($points[$i][1] + $points[$i + 1][1]) / 2];
            $outline = \array_map(static fn (array $point): array => [$middle[0] + $point[0], $middle[1] + $point[1]], self::arrow($this->emphasize));

            return [Shape::of(ShapeKind::Polygon, [$pen->shape($outline, $color)], anchor: $middle)];
        }

        return [];
    }

    /**
     * @param list<array{float, float}> $outline
     * @param array{float, float} $at
     */
    private static function head(array $outline, array $at, string $color): Shape
    {
        $points = \array_map(static fn (array $point): array => [$at[0] + $point[0], $at[1] + $point[1]], $outline);

        return Shape::of(ShapeKind::Polygon, [new Pen($color)->shape($points, $color)], anchor: $at);
    }

    /**
     * Where a label stands, as PlantUML places it along an arrow.
     *
     * @param array{TextBlock, ?string, ?string} $label
     * @return array{float, float}
     */
    private function labelAt(array $label): array
    {
        [$block, $vertical] = $label;
        [$width, $height] = $block->size();
        $points = $this->worm->points;
        [$first, $second] = [$points[0], $points[1] ?? $points[0]];
        $code = $this->worm->directions();
        $x = \max($first[0], $second[0]) + 4;
        $y = (($first[1] + $second[1]) / 2) - ($height / 2);
        $xs = \array_column($points, 0);

        return match (true) {
            'bottom' === $vertical => [\min($xs), \max(\array_column($points, 1))],
            'center' === $vertical => [\min($xs), (($this->worm->first()[1] + $this->worm->last()[1] - 10) / 2) - ($height / 2)],
            'RD' === $code => [\max($first[0], $second[0]), (($first[1] + $points[2][1]) / 2) - ($height / 2)],
            'LD' === $code => [\min($first[0], $second[0]), (($first[1] + $points[2][1]) / 2) - ($height / 2)],
            default => [$x, $y],
        };
    }
}
