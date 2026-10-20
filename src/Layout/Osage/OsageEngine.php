<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Osage;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Free\FreePlacement;
use Janalis\Viz\Layout\GraphCluster;

/**
 * osage's layout, as dot's documentation of it describes and its drawings show: each cluster's
 * clusters and nodes, then the graph's, packed in a grid as near square as their count allows,
 * row by row, clusters first, each in the order it comes and centred in its cell; columns as wide and rows as
 * tall as their largest item, a little apart. A cluster's box holds its grid in a narrow
 * margin, its label's band on top. Edges run straight, packing ignoring them.
 */
final readonly class OsageEngine implements LayoutEngine
{
    /** The room between cells, in the graph's unit. */
    private const float GAP = 4.0;
    /** The room a cluster keeps round its grid, in the graph's unit. */
    private const float MARGIN = 2.0;

    /** @return \Generator<int, null, mixed, Placement> */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $work = $this->level($graph, null);
        yield from $work;
        [$positions, $boxes] = $work->getReturn();
        $padding = $graph->spacing->padding;
        $clusters = [];
        foreach ($boxes as $cluster => [$x0, $y0, $x1, $y1]) {
            $layout = $graph->clusters[$cluster];
            $clusters[] = new GraphCluster($layout->id, $layout->label, $x0 + $padding, $y0 + $padding, $x1 + $padding, $y1 + $padding, null, null);
        }

        return FreePlacement::of($graph, $positions, $clusters);
    }

    /**
     * A level's grid, from its top left corner: every node's centre within, and every cluster's box.
     *
     * @return \Generator<int, null, mixed, array{array<int, array{float, float}>, array<int, array{float, float, float, float}>, float, float}> and the grid's width and height
     */
    private function level(LayoutGraph $graph, ?string $scope): \Generator
    {
        // Items: each child cluster with its own grid boxed, as osage packs them first, then each own node.
        $items = [];
        foreach ($graph->clusters as $cluster => $layout) {
            if ($layout->parent !== $scope) {
                continue;
            }
            $work = $this->level($graph, $layout->id);
            yield from $work;
            [$inside, $boxes, $width, $height] = $work->getReturn();
            $band = $layout->labelHeight > 0 ? $layout->labelHeight + (2 * self::MARGIN) : 0.0;
            $boxWidth = \max($width, $layout->minWidth) + (2 * self::MARGIN);
            $boxHeight = $height + $band + (2 * self::MARGIN);
            // From the box's centre: the grid below the label's band, centred across.
            [$left, $top] = [-$width / 2, (-$boxHeight / 2) + self::MARGIN + $band];
            $items[] = [
                $boxWidth,
                $boxHeight,
                \array_map(static fn (array $at): array => [$at[0] + $left, $at[1] + $top], $inside),
                [$cluster => [-$boxWidth / 2, -$boxHeight / 2, $boxWidth / 2, $boxHeight / 2]]
                    + \array_map(static fn (array $box): array => [$box[0] + $left, $box[1] + $top, $box[2] + $left, $box[3] + $top], $boxes),
            ];
        }
        foreach ($graph->nodes as $node => $layout) {
            if ($layout->cluster !== $scope) {
                continue;
            }
            $items[] = [$layout->width, $layout->height, [$node => [0.0, 0.0]], []];
        }
        $count = \count($items);
        $columns = \max(1, (int) \ceil(\sqrt($count)));
        $widths = [];
        $heights = [];
        foreach ($items as $place => [$width, $height]) {
            [$row, $column] = [\intdiv($place, $columns), $place % $columns];
            $widths[$column] = \max($widths[$column] ?? 0.0, $width);
            $heights[$row] = \max($heights[$row] ?? 0.0, $height);
        }
        $positions = [];
        $boxes = [];
        foreach ($items as $place => [, , $inside, $inner]) {
            [$row, $column] = [\intdiv($place, $columns), $place % $columns];
            $x = \array_sum(\array_slice($widths, offset: 0, length: $column)) + ($column * self::GAP) + ($widths[$column] / 2);
            $y = \array_sum(\array_slice($heights, offset: 0, length: $row)) + ($row * self::GAP) + ($heights[$row] / 2);
            foreach ($inside as $node => [$nx, $ny]) {
                $positions[$node] = [$x + $nx, $y + $ny];
            }
            foreach ($inner as $cluster => [$x0, $y0, $x1, $y1]) {
                $boxes[$cluster] = [$x + $x0, $y + $y0, $x + $x1, $y + $y1];
            }
        }
        $width = \array_sum($widths) + (\max(0, \count($widths) - 1) * self::GAP);
        $height = \array_sum($heights) + (\max(0, \count($heights) - 1) * self::GAP);

        return [$positions, $boxes, $width, $height];
    }
}
