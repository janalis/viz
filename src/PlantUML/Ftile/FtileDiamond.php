<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * A decision's diamond, as PlantUML draws its kinds: `empty`, a small diamond 24 across with
 * its test above it; `inside`, a hexagon around its test; `inside2`, the same with its north
 * label beside it; `square`, a diamond around its test. Labels stand north, south, east and
 * west of it.
 */
final class FtileDiamond extends AbstractFtile
{
    public const float HALF = 12.0;

    public function __construct(
        private readonly string $kind,
        private readonly Pen $pen,
        private readonly string $back,
        private readonly TextBlock $label = new EmptyBlock(),
        public readonly TextBlock $north = new EmptyBlock(),
        public readonly TextBlock $south = new EmptyBlock(),
        public readonly TextBlock $east = new EmptyBlock(),
        public readonly TextBlock $west = new EmptyBlock(),
    ) {}

    /** The diamond with labels, one left as it is where none is given. */
    public function with(?TextBlock $north = null, ?TextBlock $south = null, ?TextBlock $east = null, ?TextBlock $west = null): self
    {
        return new self($this->kind, $this->pen, $this->back, $this->label, $north ?? $this->north, $south ?? $this->south, $east ?? $this->east, $west ?? $this->west);
    }

    #[\Override]
    protected function measure(): Geometry
    {
        [, $northHeight] = $this->north->size();
        [$width, $height] = $this->alone();

        return match ($this->kind) {
            'empty' => new Geometry(self::HALF * 2, (self::HALF * 2) + $northHeight, self::HALF, $northHeight, (self::HALF * 2) + $northHeight),
            'inside2' => new Geometry($this->north->size()[0] > ($width / 2) ? ($width / 2) + $this->north->size()[0] : $width, $height + $northHeight, $width / 2, 0.0, $height),
            'square' => new Geometry($width, $height, $width / 2, 0.0, $height),
            default => new Geometry($width, $height + $northHeight, $width / 2, 0.0, $height),
        };
    }

    /** The height of its taller label, east or west. */
    public function sideLabelHeight(): float
    {
        return \max($this->east->size()[1], $this->west->size()[1]);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        if ('empty' === $this->kind) {
            $this->drawEmpty($canvas);

            return;
        }
        [$width, $height] = $this->alone();
        $outline = 'square' === $this->kind
            ? [[$width / 2, 0.0], [$width, $height / 2], [$width / 2, $height], [0.0, $height / 2]]
            : [[self::HALF, 0.0], [$width - self::HALF, 0.0], [$width, $height / 2], [$width - self::HALF, $height], [self::HALF, $height], [0.0, $height / 2], [self::HALF, 0.0]];
        $canvas->add(Shape::of(ShapeKind::Polygon, [$this->pen->shape($outline, $this->back)]));
        $canvas->add(...$this->north->shapes(4 + ($width / 2), $height));
        $canvas->add(...$this->south->shapes(4 + ($width / 2), $height));
        [$labelWidth, $labelHeight] = $this->label->size();
        $canvas->add(...$this->label->shapes(($width - $labelWidth) / 2, ($height - $labelHeight) / 2));
        [$westWidth, $westHeight] = $this->west->size();
        [, $eastHeight] = $this->east->size();
        $middle = 'square' === $this->kind ? self::HALF : $height / 2;
        $canvas->add(...$this->west->shapes(-$westWidth, $middle - $westHeight));
        $canvas->add(...$this->east->shapes($width, $middle - $eastHeight + ('square' === $this->kind ? 5 : 0)));
    }

    /** @return array{float, float} the diamond's own size, without the labels around it */
    private function alone(): array
    {
        [$width, $height] = $this->label->size();
        if (0.0 === $width || 0.0 === $height) {
            return [self::HALF * 2, self::HALF * 2];
        }
        if ('square' === $this->kind) {
            return [$width + (self::HALF * 2), $height + (self::HALF * 2)];
        }

        return [\max($width, self::HALF * 2) + (self::HALF * 2), \max($height, self::HALF * 2)];
    }

    private function drawEmpty(Canvas $canvas): void
    {
        [, $top] = $this->north->size();
        $size = self::HALF * 2;
        $canvas->add(Shape::of(ShapeKind::Polygon, [$this->pen->shape([
            [self::HALF, $top],
            [$size, $top + self::HALF],
            [self::HALF, $top + $size],
            [0.0, $top + self::HALF],
            [self::HALF, $top],
        ], $this->back)]));
        $canvas->add(...$this->north->shapes(self::HALF * 1.5, 0.0));
        $canvas->add(...$this->south->shapes(self::HALF * 1.5, $top + $size));
        [$westWidth, $westHeight] = $this->west->size();
        [, $eastHeight] = $this->east->size();
        $canvas->add(...$this->west->shapes(-$westWidth, $top - $westHeight + self::HALF));
        $canvas->add(...$this->east->shapes($size, $top - $eastHeight + self::HALF));
    }
}
