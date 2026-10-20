<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * An action: its text in a box of its style — rounded by default, or one of SDL's and UML's
 * shapes — padded as its style says, at least its minimum width, its flow through its middle.
 */
final class FtileBox extends AbstractFtile
{
    /** How far the shapes with a point reach past the box's right side. */
    private const float SHIELD = 10.0;
    private const float DELTA = 10.0;

    private readonly Label $text;

    /** @param list<string> $label */
    public function __construct(
        array $label,
        private readonly Style $style,
        /** `plain`, or one of the SDL and UML shapes */
        private readonly string $boxStyle,
        private readonly LinkRendering $inLink,
    ) {
        $this->text = new Label($label, $style, \strtolower($style->value('HorizontalAlignment', 'left')));
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    protected function measure(): Geometry
    {
        [$width, $height] = $this->text->size();
        [$top, $right, $bottom, $left] = $this->style->sides('Padding');
        $width = \max($width + $left + $right, $this->style->number('MinimumWidth'));
        $height += $top + $bottom;

        return new Geometry($width + $this->shield(), $height, $width / 2, 0.0, $height);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $geometry = $this->geometry();
        [$textWidth] = $this->text->size();
        [$top, $right, $bottom, $left] = $this->style->sides('Padding');
        $canvas->add($this->outline($geometry->width - $this->shield(), $geometry->height));
        [$x, $y] = match (\strtolower($this->style->value('HorizontalAlignment', 'left'))) {
            'right' => [$geometry->width - $textWidth - $right, $bottom],
            'center' => [($geometry->width - $textWidth) / 2, $bottom],
            default => [$left, $top],
        };
        $canvas->add(...$this->text->shapes($x, $y));
    }

    private function shield(): float
    {
        return \in_array($this->boxStyle, ['input', 'output', 'objectsignal', 'trigger', 'sendsignal', 'acceptevent', 'timeevent'], strict: true) ? self::SHIELD : 0.0;
    }

    /** The box's outline, a width across without its shield. */
    private function outline(float $width, float $height): Shape
    {
        $pen = Pen::of($this->style);
        $back = $this->style->color('BackGroundColor', '#f1f1f1');
        $d = self::DELTA;
        $half = $height / 2;

        return match ($this->boxStyle) {
            'input', 'trigger' => Shape::of(ShapeKind::Polygon, [$pen->shape([[0.0, 0.0], [$width + $d, 0.0], [$width, $half], [$width + $d, $height], [0.0, $height]], $back)]),
            'output', 'sendsignal' => Shape::of(ShapeKind::Polygon, [$pen->shape([[0.0, 0.0], [$width, 0.0], [$width + $d, $half], [$width, $height], [0.0, $height]], $back)]),
            'procedure' => Shape::of(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $height, $back), $pen->line(5.0, 0.0, 5.0, $height), $pen->line($width - 5, 0.0, $width - 5, $height)]),
            'load' => Shape::of(ShapeKind::Polygon, [$pen->shape([[0.0, 0.0], [$width - $d, 0.0], [$width, $height], [$d, $height]], $back)]),
            'save' => Shape::of(ShapeKind::Polygon, [$pen->shape([[$d, 0.0], [$width, 0.0], [$width - $d, $height], [0.0, $height]], $back)]),
            'continuous' => Shape::of(ShapeKind::Path, [$pen->path([[5.0, 0.0], [0.0, $half], [5.0, $height]]), $pen->path([[$width - 5, 0.0], [$width, $half], [$width - 5, $height]])]),
            'task', 'object' => Shape::of(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $height, $back)]),
            'objectsignal' => Shape::of(ShapeKind::Polygon, [$pen->shape([[-$d, 0.0], [$width, 0.0], [$width + $d, $half], [$width, $height], [-$d, $height], [0.0, $half]], $back)]),
            'acceptevent' => Shape::of(ShapeKind::Polygon, [$pen->shape([[-$d, 0.0], [$width, 0.0], [$width, $height], [-$d, $height], [0.0, $half]], $back)]),
            'timeevent' => Shape::of(ShapeKind::Polygon, [$pen->shape([
                [($width / 2) - ($height / 3), $height / 3],
                [($width / 2) + ($height / 3), $height / 3],
                [($width / 2) - ($height / 3), $height],
                [($width / 2) + ($height / 3), $height],
            ], $back)]),
            default => Shape::of(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $height, $back, $this->style->number('RoundCorner'))]),
        };
    }
}
