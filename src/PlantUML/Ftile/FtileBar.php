<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * Where a fork starts or joins: a bar 6 high across its flows, its label after it; or where a
 * split starts or joins: a thin line from its first flow to its last.
 */
final class FtileBar extends AbstractFtile
{
    private float $width = 0.0;
    private float $first = 0.0;
    private float $last = 0.0;

    public function __construct(
        /** Whether it is a split's thin line rather than a fork's bar. */
        private readonly bool $thin,
        private readonly ?string $color,
        private readonly TextBlock $label = new EmptyBlock(),
    ) {}

    /** Its width, and for a thin line where it starts and ends. */
    public function span(float $width, float $first = 0.0, float $last = 0.0): void
    {
        $this->width = $width;
        $this->first = $first;
        $this->last = $last;
    }

    #[\Override]
    protected function measure(): Geometry
    {
        if ($this->thin) {
            return new Geometry($this->width, 1.5, $this->width / 2, 0.0, 1.5);
        }
        [$label] = $this->label->size();
        $height = 6.0;

        return new Geometry($this->width + ($label > 0 ? $label + 5 : 0.0), $height, $this->width / 2, 0.0, $height);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        if ($this->thin) {
            if (null !== $this->color) {
                $canvas->add(Shape::of(ShapeKind::Line, [new Pen($this->color, 1.5)->line($this->first, 0.0, $this->last, 0.0)]));
            }

            return;
        }
        $color = (string) $this->color;
        $canvas->add(Shape::of(ShapeKind::Rectangle, [new Pen($color)->rectangle(0.0, 0.0, $this->width, 6.0, $color, 5.0)], ignoreX: true));
        [, $height] = $this->label->size();
        $canvas->add(...$this->label->shapes($this->width + 5, -$height / 2));
    }
}
