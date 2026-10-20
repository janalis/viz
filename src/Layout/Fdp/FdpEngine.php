<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Fdp;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Free\FreePlacement;
use Janalis\Viz\Layout\Free\Stress;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Source\Unsupported;

/**
 * fdp's layout, cluster by cluster as fdp lays one out: each cluster's own nodes and
 * clusters first, then the cluster as one box among its siblings, an edge counting between
 * the items that hold its ends. Each level is placed by stress majorization ({@see Stress}),
 * an edge as long as its ends' half sizes and fdp's `K` between them, then pushed apart where
 * boxes overlap. fdp itself runs a spring model; the drawings it makes look alike.
 */
final readonly class FdpEngine implements LayoutEngine
{
    /** The most items one level may hold: each pass costs the square of its size. */
    public const int MAX_ITEMS = 600;
    /** The room a cluster keeps round what it holds, in the graph's unit. */
    private const float MARGIN = 8.0;
    /** How many passes push overlapping boxes apart. */
    private const int SEPARATIONS = 50;

    public function __construct(
        /** The room between two boxes an edge joins, in the graph's unit: fdp's `K`. */
        private float $spring = 21.6,
    ) {}

    /**
     * @return \Generator<int, null, mixed, Placement>
     * @throws Unsupported when one level holds more than {@see self::MAX_ITEMS} items
     */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $work = $this->level($graph, null);
        yield from $work;
        [$positions, $boxes] = $work->getReturn();
        [$left, $top] = [\INF, \INF];
        foreach ($positions as $node => [$x, $y]) {
            $left = \min($left, $x - ($graph->nodes[$node]->width / 2));
            $top = \min($top, $y - ($graph->nodes[$node]->height / 2));
        }
        foreach ($boxes as [$x0, $y0]) {
            [$left, $top] = [\min($left, $x0), \min($top, $y0)];
        }
        $positions = \array_map(static fn (array $at): array => [$at[0] - $left, $at[1] - $top], $positions);
        $padding = $graph->spacing->padding;
        $clusters = [];
        foreach ($boxes as $cluster => [$x0, $y0, $x1, $y1]) {
            $layout = $graph->clusters[$cluster];
            $clusters[] = new GraphCluster($layout->id, $layout->label, $x0 - $left + $padding, $y0 - $top + $padding, $x1 - $left + $padding, $y1 - $top + $padding, null, null);
        }

        return FreePlacement::of($graph, $positions, $clusters);
    }

    /**
     * Lays out one level, a cluster's or the graph's: its child clusters first, then its
     * own nodes and those clusters' boxes together.
     *
     * @return \Generator<int, null, mixed, array{array<int, array{float, float}>, array<int, array{float, float, float, float}>}> every node's centre within, and every cluster's box
     */
    private function level(LayoutGraph $graph, ?string $scope): \Generator
    {
        // Items: each own node, then each child cluster, with its size and what it holds.
        $items = [];
        foreach ($graph->nodes as $node => $layout) {
            if ($layout->cluster !== $scope) {
                continue;
            }
            $items[] = new FdpItem(false, $layout->width, $layout->height, [$node => [0.0, 0.0]]);
        }
        foreach ($graph->clusters as $cluster => $layout) {
            if ($layout->parent !== $scope) {
                continue;
            }
            $work = $this->level($graph, $layout->id);
            yield from $work;
            [$inside, $boxes] = $work->getReturn();
            [$x0, $y0, $x1, $y1] = self::frame($graph, $inside, $boxes, $layout->labelHeight, $layout->minWidth);
            [$cx, $cy] = [($x0 + $x1) / 2, ($y0 + $y1) / 2];
            $within = [$cluster => [$x0 - $cx, $y0 - $cy, $x1 - $cx, $y1 - $cy]];
            foreach ($boxes as $inner => [$ix0, $iy0, $ix1, $iy1]) {
                $within[$inner] = [$ix0 - $cx, $iy0 - $cy, $ix1 - $cx, $iy1 - $cy];
            }
            $items[] = new FdpItem(true, $x1 - $x0, $y1 - $y0, \array_map(static fn (array $at): array => [$at[0] - $cx, $at[1] - $cy], $inside), $within);
        }
        if (\count($items) > self::MAX_ITEMS) {
            throw new Unsupported('The graph is too large to lay out natively with fdp: one level holds '.\count($items).' items');
        }
        $centres = [];
        foreach (Stress::parts($this->lengths($graph, $items)) as $part) {
            $work = Stress::majorize($part, $this->lengths($graph, $items));
            yield from $work;
            $centres[] = $work->getReturn();
        }
        $placed = self::separate(self::sideBySide($centres, $items), $items);
        $positions = [];
        $boxes = [];
        foreach ($items as $item => $held) {
            [$x, $y] = $placed[$item];
            foreach ($held->inside as $node => [$nx, $ny]) {
                $positions[$node] = [$x + $nx, $y + $ny];
            }
            foreach ($held->boxes as $cluster => [$x0, $y0, $x1, $y1]) {
                $boxes[$cluster] = [$x + $x0, $y + $y0, $x + $x1, $y + $y1];
            }
        }

        return [$positions, $boxes];
    }

    /**
     * How long the edges between a level's items would be: their half sizes and `K` apart.
     *
     * @param list<FdpItem> $items
     * @return list<array<int, float>>
     */
    private function lengths(LayoutGraph $graph, array $items): array
    {
        $holder = [];
        foreach ($items as $item => $held) {
            foreach (\array_keys($held->inside) as $node) {
                $holder[$graph->nodes[$node]->id] = $item;
            }
        }
        $lengths = \array_fill(0, \count($items), []);
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$holder[$edge->from] ?? null, $holder[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $length = $items[$tail]->half() + $items[$head]->half() + $this->spring;
            $lengths[$tail][$head] = \min($lengths[$tail][$head] ?? \INF, $length);
            $lengths[$head][$tail] = $lengths[$tail][$head];
        }

        return $lengths;
    }

    /**
     * The parts of a level side by side, from the left, each aligned at its top.
     *
     * @param list<array<int, array{float, float}>> $parts
     * @param list<FdpItem> $items
     * @return array<int, array{float, float}>
     */
    private static function sideBySide(array $parts, array $items): array
    {
        $placed = [];
        $cursor = 0.0;
        foreach ($parts as $part) {
            [$left, $top, $right] = [\INF, \INF, -\INF];
            foreach ($part as $item => [$x, $y]) {
                $left = \min($left, $x - ($items[$item]->width / 2));
                $top = \min($top, $y - ($items[$item]->height / 2));
                $right = \max($right, $x + ($items[$item]->width / 2));
            }
            foreach ($part as $item => [$x, $y]) {
                $placed[$item] = [$x - $left + $cursor, $y - $top];
            }
            $cursor += $right - $left + self::MARGIN;
        }

        return $placed;
    }

    /**
     * Boxes pushed apart where they overlap, each pair along the line between their centres,
     * by half the overlap and a margin each, a few passes over.
     *
     * @param array<int, array{float, float}> $placed
     * @param list<FdpItem> $items
     * @return array<int, array{float, float}>
     */
    private static function separate(array $placed, array $items): array
    {
        for ($pass = 0; $pass < self::SEPARATIONS; ++$pass) {
            $moved = false;
            foreach ($placed as $one => [$x, $y]) {
                foreach ($placed as $other => [$ox, $oy]) {
                    if ($other <= $one) {
                        continue;
                    }
                    $acrossOverlap = (($items[$one]->width + $items[$other]->width) / 2) + self::MARGIN - \abs($x - $ox);
                    $downOverlap = (($items[$one]->height + $items[$other]->height) / 2) + self::MARGIN - \abs($y - $oy);
                    if ($acrossOverlap <= 0 || $downOverlap <= 0) {
                        continue;
                    }
                    // Apart the shorter way, so the boxes move as little as they can.
                    [$dx, $dy] = $acrossOverlap < $downOverlap ? [(($x <= $ox ? -1 : 1) * $acrossOverlap) / 2, 0.0] : [0.0, (($y <= $oy ? -1 : 1) * $downOverlap) / 2];
                    $placed[$one] = [$placed[$one][0] + $dx, $placed[$one][1] + $dy];
                    $placed[$other] = [$placed[$other][0] - $dx, $placed[$other][1] - $dy];
                    [$x, $y] = $placed[$one];
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }

        return $placed;
    }

    /**
     * A cluster's box round what it holds: a margin all round, its label's band on top, as wide as its label needs.
     *
     * @param array<int, array{float, float}> $inside
     * @param array<int, array{float, float, float, float}> $boxes
     * @return array{float, float, float, float}
     */
    private static function frame(LayoutGraph $graph, array $inside, array $boxes, float $labelHeight, float $labelWidth): array
    {
        [$left, $top, $right, $bottom] = [\INF, \INF, -\INF, -\INF];
        foreach ($inside as $node => [$x, $y]) {
            $layout = $graph->nodes[$node];
            [$left, $top] = [\min($left, $x - ($layout->width / 2)), \min($top, $y - ($layout->height / 2))];
            [$right, $bottom] = [\max($right, $x + ($layout->width / 2)), \max($bottom, $y + ($layout->height / 2))];
        }
        foreach ($boxes as [$x0, $y0, $x1, $y1]) {
            [$left, $top, $right, $bottom] = [\min($left, $x0), \min($top, $y0), \max($right, $x1), \max($bottom, $y1)];
        }
        if (\INF === $left) {
            [$left, $top, $right, $bottom] = [0.0, 0.0, 0.0, 0.0];
        }
        $width = \max($right - $left, $labelWidth);
        $middle = ($left + $right) / 2;

        return [$middle - ($width / 2) - self::MARGIN, $top - self::MARGIN - ($labelHeight > 0 ? $labelHeight + self::MARGIN : 0.0), $middle + ($width / 2) + self::MARGIN, $bottom + self::MARGIN];
    }
}
