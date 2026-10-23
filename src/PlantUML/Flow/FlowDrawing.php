<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Flow;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\RoundedBox;

/**
 * A flow diagram drawn as PlantUML's `FlowDiagram` draws it: each cell 100 across and 35
 * down, the field as big as the cells its boxes take, with no margin; each box centred on
 * its four cells, its label in serif 14, 10 in, in a yellow box with corners rounded 12.5;
 * each path a red line from the middle of one box's side to the other's, a dot at its end.
 */
final class FlowDrawing
{
    private const float CELL_WIDTH = 100.0;
    private const float CELL_HEIGHT = 35.0;
    private const float MARGIN = 10.0;
    /** PlantUML's serif line, against its size. */
    private const float SERIF = 1.149_9;
    private const float SIZE = 14.0;
    private const string LINE = '#a80036';
    private const string BACK = '#fefece';

    public static function draw(PlantUmlFlow $flow): Drawing
    {
        if ([] === $flow->tiles) {
            return PlantUmlFrame::bare($flow->document, [], 0.0, 0.0);
        }
        $left = \min(\array_map(static fn (FlowTile $tile): int => $tile->xmin, $flow->tiles));
        $top = \min(\array_map(static fn (FlowTile $tile): int => $tile->ymin, $flow->tiles));
        $right = \max(\array_map(static fn (FlowTile $tile): int => $tile->xmax(), $flow->tiles));
        $bottom = \max(\array_map(static fn (FlowTile $tile): int => $tile->ymax(), $flow->tiles));
        $marks = [];
        foreach ($flow->tiles as $tile) {
            [$width, $height] = self::size($tile);
            $x = (($tile->xmin - $left) * self::CELL_WIDTH) + (((2 * self::CELL_WIDTH) - $width) / 2);
            $y = (($tile->ymin - $top) * self::CELL_HEIGHT) + (((2 * self::CELL_HEIGHT) - $height) / 2);
            $marks[] = new Mark(MarkKind::Polygon, RoundedBox::points($x, $y, $width, $height, 12.5), stroke: self::LINE, fill: self::BACK, strokeWidth: 1.5);
            $text = self::text($tile);
            \array_push($marks, ...$text->marks($x + self::MARGIN, $y + self::MARGIN, $text->size()[0], 'left'));
        }
        foreach ($flow->paths as [$start, $from, $end, $to]) {
            [$x1, $y1] = self::side($start, $from, $left, $top);
            [$x2, $y2] = self::side($end, $to, $left, $top);
            $marks[] = new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: self::LINE, strokeWidth: 1.0);
            $marks[] = DescriptionBox::ellipse($x2 - 3, $y2 - 3, 7.0, 7.0, self::LINE, self::LINE, 1.0);
        }

        // As PlantUML sizes it, as measured on its output: 1 wider and taller than its cells.
        return PlantUmlFrame::bare($flow->document, $marks, (($right - $left + 1) * self::CELL_WIDTH) + 1, (($bottom - $top + 1) * self::CELL_HEIGHT) + 1);
    }

    /** @return array{float, float} as `ActivityBox`: its label and 10 all round */
    private static function size(FlowTile $tile): array
    {
        return [self::text($tile)->size()[0] + (2 * self::MARGIN), (self::SIZE * self::SERIF) + (2 * self::MARGIN)];
    }

    /** As PlantUML, which writes the label as one line, a `\n` in it and all. */
    private static function text(FlowTile $tile): PlantUmlText
    {
        return new PlantUmlText([$tile->label], self::SIZE);
    }

    /** @return array{float, float} the middle of a box's side, as `movePoint` finds it */
    private static function side(FlowTile $tile, string $side, int $left, int $top): array
    {
        [$width, $height] = self::size($tile);
        [$dx, $dy] = FlowTile::step($side, 1);
        $x = ($tile->xmin - $left + 1) * self::CELL_WIDTH;
        $y = ($tile->ymin - $top + 1) * self::CELL_HEIGHT;

        return [$x + (($dx * $width) / 2), $y + (($dy * $height) / 2)];
    }
}
