<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * A circle where the flow starts — a disc, 20 across — stops — a ring round a disc, 22 across
 * — ends every flow — a crossed circle, 20 across — or a connector with its letter in it.
 * The flow goes on past a start or a connector only.
 */
final class FtileCircle extends AbstractFtile
{
    public function __construct(
        /** `start`, `stop`, `end` or `spot` */
        private readonly string $kind,
        private readonly Style $style,
        private readonly string $spot = '',
        private readonly ?string $color = null,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        $size = 'stop' === $this->kind ? 22.0 : 20.0;

        return new Geometry($size, $size, $size / 2, 0.0, \in_array($this->kind, ['start', 'spot'], strict: true) ? $size : null);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $line = $this->style->color('LineColor', '#222222');
        $back = $this->style->color('BackGroundColor', '#222222');
        $pen = Pen::of($this->style, '#222222');
        match ($this->kind) {
            'start' => $canvas->add(Shape::of(ShapeKind::Ellipse, [$pen->ellipse(0.0, 0.0, 20.0, 20.0, $back)])),
            'stop' => $canvas->add(
                Shape::of(ShapeKind::Ellipse, [$pen->ellipse(0.0, 0.0, 22.0, 22.0, 'none')]),
                Shape::of(ShapeKind::Ellipse, [$pen->withWidth(1.0)->withDashes(null)->ellipse(5.0, 5.0, 12.0, 12.0, $back)]),
            ),
            'end' => $this->cross($canvas, $pen, $line),
            default => $this->connector($canvas, $pen),
        };
    }

    private function cross(Canvas $canvas, Pen $pen, string $line): void
    {
        $size = (20 - 2.5) / \sqrt(2);
        $delta = (20 - $size) / 2;
        $cross = new Pen($line, 2.5);
        $canvas->add(
            Shape::of(ShapeKind::Ellipse, [$pen->ellipse(0.0, 0.0, 20.0, 20.0, 'none')]),
            Shape::of(ShapeKind::Line, [$cross->line($delta, $delta, $delta + $size, $delta + $size)]),
            Shape::of(ShapeKind::Line, [$cross->line($delta, 20 - $delta, $delta + $size, 20 - $delta - $size)]),
        );
    }

    private function connector(Canvas $canvas, Pen $pen): void
    {
        $back = PlantUmlColor::parse((string) $this->color) ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $canvas->add(Shape::of(ShapeKind::Ellipse, [$pen->ellipse(0.0, 0.0, 20.0, 20.0, $back)]));
        $letter = new PlantUmlText([\mb_substr($this->spot, start: 0, length: 1)], $this->style->fontSize(), $this->style->face(), $this->style->color('FontColor', '#000000'));
        [$width, $height] = $letter->size();
        $canvas->add(new Shape(ShapeKind::Text, $letter->marks(10 - ($width / 2), 10 - ($height / 2), $width), 10 - ($width / 2), 10 - ($height / 2), 10 + ($width / 2), 10 + ($height / 2)));
    }
}
