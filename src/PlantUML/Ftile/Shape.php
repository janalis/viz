<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/** Marks drawn as one of PlantUML's shapes, with the box it spans and whether compression skips it across or down. */
final readonly class Shape
{
    /** @param list<Mark> $marks */
    public function __construct(
        public ShapeKind $kind,
        public array $marks,
        public float $minX,
        public float $minY,
        public float $maxX,
        public float $maxY,
        public bool $ignoreX = false,
        public bool $ignoreY = false,
        /** @var ?array{float, float} where it was drawn from, which compression moves it by; its top left when not given */
        public ?array $anchor = null,
        /** The width centred text is centred across, from its anchor. */
        public float $span = 0.0,
        /** How far above its box PlantUML's limit finder takes it to reach: text in a big font. */
        public float $rise = 0.0,
        /** @var array{float, float} how far past its box PlantUML's compression takes it down: above its top, and short of its bottom */
        public array $overhang = [0.0, 0.0],
    ) {}

    /** Text centred across a width from a point, once compressed, taking no room. */
    public static function centered(self $text, float $x, float $span): self
    {
        return new self(ShapeKind::Centered, $text->marks, $text->minX, $text->minY, $text->maxX, $text->maxY, anchor: [$x, $text->minY], span: $span, rise: $text->rise, overhang: $text->overhang);
    }

    /**
     * @param list<Mark> $marks
     * @param ?array{float, float} $anchor
     */
    public static function of(ShapeKind $kind, array $marks, bool $ignoreX = false, ?array $anchor = null): self
    {
        $xs = [];
        $ys = [];
        foreach ($marks as $mark) {
            foreach ($mark->points as [$x, $y]) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }

        return new self($kind, $marks, [] === $xs ? 0.0 : \min($xs), [] === $ys ? 0.0 : \min($ys), [] === $xs ? 0.0 : \max($xs), [] === $ys ? 0.0 : \max($ys), $ignoreX, anchor: $anchor);
    }

    /**
     * A shape with its shadow, as PlantUML casts one when a style's `Shadowing` is set: a
     * darkened copy of its filled outlines, its distance down and right, which compression passes over,
     * and the room PlantUML's limit finder gives it, twice that distance past the shape.
     *
     * @return list<self> the shadow, the room it takes, then the shape
     */
    public static function shadowed(self $shape, float $distance): array
    {
        if ($distance <= 0.0) {
            return [$shape];
        }
        $marks = [];
        foreach ($shape->marks as $mark) {
            $marks[] = new Mark(
                MarkKind::Polygon,
                \array_map(static fn (array $point): array => [$point[0] + $distance, $point[1] + $distance], $mark->points),
                stroke: 'none',
                fill: '#000000',
                strokeWidth: 0.0,
                fillOpacity: 0.2,
            );
        }
        [$x, $y] = [$shape->maxX + (2 * $distance) - 1, $shape->maxY + (2 * $distance) - 1];

        return [
            // Taken as drawn, as PlantUML's limit finder takes a shadow it draws by a filter: no wider than it is.
            new self(ShapeKind::Path, $marks, $shape->minX + $distance, $shape->minY + $distance, $shape->maxX + $distance, $shape->maxY + $distance, ignoreX: true, ignoreY: true),
            new self(ShapeKind::Line, [], $x, $y, $x, $y),
            $shape,
        ];
    }

    /** Room taken at a point, nothing drawn. */
    public static function empty(float $x, float $y, float $width, float $height): self
    {
        return new self(ShapeKind::Empty, [], $x, $y, $x + $width, $y + $height);
    }

    public function moved(float $dx, float $dy): self
    {
        return new self(
            $this->kind,
            \array_map(static fn (Mark $mark): Mark => self::movedMark($mark, $dx, $dy), $this->marks),
            $this->minX + $dx,
            $this->minY + $dy,
            $this->maxX + $dx,
            $this->maxY + $dy,
            $this->ignoreX,
            $this->ignoreY,
            null === $this->anchor ? null : [$this->anchor[0] + $dx, $this->anchor[1] + $dy],
            $this->span,
            $this->rise,
            $this->overhang,
        );
    }

    /** The shape taken as its box, not reaching past it. */
    public function settled(): self
    {
        return new self($this->kind, $this->marks, $this->minX, $this->minY, $this->maxX, $this->maxY, $this->ignoreX, $this->ignoreY, $this->anchor, $this->span);
    }

    /** @return array{float, float} where it was drawn from */
    public function anchor(): array
    {
        return $this->anchor ?? [$this->minX, $this->minY];
    }

    /**
     * The shape with each of its points moved through a function across, or down.
     *
     * @param \Closure(float): float $x
     * @param \Closure(float): float $y
     */
    public function mapped(\Closure $x, \Closure $y): self
    {
        $marks = \array_map(static fn (Mark $mark): Mark => self::withPoints($mark, \array_map(static fn (array $point): array => [$x($point[0]), $y($point[1])], $mark->points)), $this->marks);
        $anchor = null === $this->anchor ? null : [$x($this->anchor[0]), $y($this->anchor[1])];

        return new self($this->kind, $marks, $x($this->minX), $y($this->minY), $x($this->maxX), $y($this->maxY), $this->ignoreX, $this->ignoreY, $anchor, $this->span, $this->rise, $this->overhang);
    }

    /**
     * The box PlantUML's limit finder gives the shape: a polygon 10 wider either side, a
     * rectangle and an ellipse 1 short of their far sides, a rectangle 1 before its near ones.
     *
     * @return array{float, float, float, float}
     */
    public function limits(): array
    {
        return match ($this->kind) {
            ShapeKind::Polygon => [$this->minX - 10, $this->minY, $this->maxX + 10, $this->maxY],
            ShapeKind::Rectangle => [$this->minX - 1, $this->minY - 1, $this->maxX - 1, $this->maxY - 1],
            ShapeKind::Ellipse => [$this->minX, $this->minY, $this->maxX - 1, $this->maxY - 1],
            default => [$this->minX, $this->minY - $this->rise, $this->maxX, $this->maxY],
        };
    }

    private static function movedMark(Mark $mark, float $dx, float $dy): Mark
    {
        return self::withPoints($mark, \array_map(static fn (array $point): array => [$point[0] + $dx, $point[1] + $dy], $mark->points));
    }

    /** @param list<array{float, float}> $points */
    private static function withPoints(Mark $mark, array $points): Mark
    {
        return $mark->with(['points' => $points]);
    }
}
