<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * An action written `<<icon>>`, as PlantUML's `FtileBoxEmoji` draws one: no box, the emoji its
 * first line starts with twice its size unless written with a `*` scale, and after a gap the rest
 * of that line, the two centred on each other; its flow through the emoji's middle. Its lines as
 * they are where none starts it.
 */
final class FtileBoxEmoji extends AbstractFtile
{
    private const float MARGIN = 5.0;

    private readonly Label $emoji;

    private readonly ?TextBlock $name;

    /** @param list<string> $label */
    public function __construct(
        array $label,
        Style $style,
        private readonly LinkRendering $inLink,
    ) {
        $first = $label[0] ?? '';
        $match = [];
        if (1 !== \preg_match('/^'.PlantUmlText::EMOJI.'/', $first, $match)) {
            $this->emoji = new Label($label, $style);
            $this->name = null;

            return;
        }
        $part = $match[0];
        $this->emoji = new Label([\str_contains($part, '*') ? $part : \str_replace(':>', replace: ':*2>', subject: $part)], $style);
        $name = \substr($first, \strlen($part));
        // Nothing after it is a line PlantUML sets as a space.
        $this->name = '' === $name ? self::space($style) : new Label([$name], $style);
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inLink;
    }

    #[\Override]
    protected function measure(): Geometry
    {
        [$width, $height] = $this->emoji->size();
        if (null === $this->name) {
            return new Geometry($width, $height, $width / 2, 0.0, $height);
        }
        [$nameWidth, $nameHeight] = $this->name->size();
        $lift = \max(0.0, ($nameHeight - $height) / 2);

        return new Geometry($width + self::MARGIN + $nameWidth, \max($height, $nameHeight), $width / 2, $lift, $height + $lift);
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        if (null === $this->name) {
            $canvas->add(...$this->emoji->shapes(0.0, 0.0));

            return;
        }
        [$width, $height] = $this->emoji->size();
        $delta = ($height - $this->name->size()[1]) / 2;
        // The emoji as PlantUML's limit finder takes it: each shape as drawn, a circle, the one edged, as an ellipse.
        foreach ($this->emoji->shapes(0.0, \max(0.0, -$delta)) as $shape) {
            foreach ($shape->marks as $mark) {
                $canvas->add(Shape::of('none' === $mark->stroke ? ShapeKind::Path : ShapeKind::Ellipse, [$mark]));
            }
        }
        [$x, $y] = [$width + self::MARGIN, \max(0.0, $delta)];
        [$nameWidth, $nameHeight] = $this->name->size();
        $canvas->add(...$this->name instanceof Label ? $this->name->shapes($x, $y) : [new Shape(ShapeKind::Text, [], $x, $y, $x + $nameWidth, $y + $nameHeight)]);
    }

    /** Room for a space, in a style's font. */
    private static function space(Style $style): EmptyBlock
    {
        $family = PlantUmlMetrics::family($style->value('FontName'));

        return new EmptyBlock(PlantUmlMetrics::width(' ', $style->fontSize(), $style->face(), $family), PlantUmlText::lineHeight($style->fontSize(), $family));
    }
}
