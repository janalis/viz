<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlArrow;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;

/**
 * A JSON or YAML diagram laid out as PlantUML's `SmetanaForJson` lays it out: each object and
 * array a table, a node of dot laid out down and turned to run left to right — so a node as
 * wide as its table is tall — each container joined to the table it holds by an edge from its
 * row, as the record port of its row leaves it; dot's own separations, 18 between nodes and 36
 * between ranks. Each edge a dashed curve from a spot in its row to an arrowhead on its table.
 */
final class JsonDrawing
{
    private const float NODE_SEPARATION = 18.0;
    private const float RANK_SEPARATION = 36.0;
    /** How far back into its row an edge starts, as PlantUML's `JsonCurve` draws its spot. */
    private const float SPOT_BACK = 13.0;
    private const float SPOT = 3.0;
    /** How long dot draws an arrowhead at `arrowsize=.75`. */
    private const float ARROW = 7.5;

    /** @var list<array{JsonTable, ?int, int}> each table, its parent's index and the row it hangs from, parents first */
    private array $tables = [];

    private function __construct(
        private readonly PlantUmlJson $diagram,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlJson $diagram): \Generator
    {
        $drawing = new self($diagram);
        $drawing->table($diagram->root, $diagram->highlights, null, 0);
        $work = $drawing->place();
        yield from $work;
        [$marks, $width, $height] = $work->getReturn();

        return PlantUmlFrame::drawing($diagram->document, $marks, $width, $height);
    }

    /** @param list<JsonHighlight> $highlights */
    private function table(JsonNode $node, array $highlights, ?int $parent, int $row): void
    {
        $table = new JsonTable($node, $this->diagram->document->styles, JsonReader::KINDS[$this->diagram->document->kind], $highlights);
        $index = \count($this->tables);
        $this->tables[] = [$table, $parent, $row];
        foreach ($table->children() as $childRow => [$child, $under]) {
            $this->table($child, $under, $index, $childRow);
        }
    }

    /** @return \Generator<int, null, mixed, array{list<Mark>, float, float}> the marks, from the top left, and how far they reach */
    private function place(): \Generator
    {
        $nodes = [];
        $edges = [];
        foreach ($this->tables as $index => [$table, $parent, $row]) {
            [$width, $height] = $table->size();
            // Laid out down, then turned: across the ranks a node runs as far as its table is tall.
            $nodes[] = new LayoutNode('n'.$index, '', $height, $width, order: $index);
            if (null === $parent) {
                continue;
            }
            $edges[] = new LayoutEdge(\count($edges), 'n'.$parent, 'n'.$index, tailPort: $this->port($this->tables[$parent][0], $row));
        }
        $placing = new DotEngine()->place(new LayoutGraph($nodes, $edges, spacing: new Spacing(self::RANK_SEPARATION, self::NODE_SEPARATION, 0.0)));
        yield from $placing;
        $placement = $placing->getReturn();
        $corners = [];
        foreach ($this->tables as $index => [$table]) {
            [$width, $height] = $table->size();
            $node = $placement->endpoints['n'.$index];
            // Turned: down the ranks is across, across them down.
            $corners[] = [$node->y - ($width / 2), $node->x - ($height / 2)];
        }
        $left = \min(\array_column($corners, 0));
        $top = \min(\array_column($corners, 1));
        $corners = \array_map(static fn (array $corner): array => [$corner[0] - $left, $corner[1] - $top], $corners);
        $marks = [];
        foreach ($this->tables as $index => [$table]) {
            \array_push($marks, ...$table->marks(...$corners[$index]));
        }
        foreach ($this->tables as $index => [$table, $parent, $row]) {
            if (null === $parent) {
                continue;
            }
            \array_push($marks, ...$this->edge($this->tables[$parent][0], $corners[$parent], $row, $table, $corners[$index]));
        }
        [$width, $height] = [0.0, 0.0];
        foreach ($marks as $mark) {
            foreach ($mark->points as [$x, $y]) {
                [$width, $height] = [\max($width, $x), \max($height, $y)];
            }
        }

        return [$marks, $width, $height];
    }

    /** Where across its node a row's port stands, from -0.5 at the first row's edge to 0.5 at the last's. */
    private function port(JsonTable $table, int $row): float
    {
        $heights = $table->heights();
        $total = \array_sum($heights);

        return 0.0 === $total ? 0.0 : ((\array_sum(\array_slice($heights, offset: 0, length: $row)) + ($heights[$row] / 2)) / $total) - 0.5;
    }

    /**
     * An edge from a row of one table to another, as dot routes it and PlantUML's `JsonCurve`
     * draws it: leaving the row's end level, easing towards the other table's middle, cut at its
     * side, with a spot back in the row and an arrowhead where it reaches the table.
     *
     * @param array{float, float} $from the first table's top left
     * @param array{float, float} $to the other's
     * @return list<Mark>
     */
    private function edge(JsonTable $parent, array $from, int $row, JsonTable $child, array $to): array
    {
        $style = $this->diagram->document->styles->style(['root', 'element', JsonReader::KINDS[$this->diagram->document->kind], 'arrow']);
        $color = $style->color('LineColor', '#000000');
        if ('none' === $color) {
            return [];
        }
        $heights = $parent->heights();
        $start = [$from[0] + $parent->size()[0], $from[1] + \array_sum(\array_slice($heights, offset: 0, length: $row)) + ($heights[$row] / 2)];
        [$childWidth, $childHeight] = $child->size();
        $centre = [$to[0] + ($childWidth / 2), $to[1] + ($childHeight / 2)];
        $reach = ($centre[0] - $start[0]) / 2;
        $curve = [$start, [$start[0] + $reach, $start[1]], [$centre[0] - $reach, $centre[1]], $centre];
        // Where it crosses the table's side, its tip, and its arrowhead's length back along it, its end.
        $tip = self::solve(static fn (float $t): float => self::at($curve, $t)[0] - $to[0], 0.0, 1.0);
        $end = self::solve(static fn (float $t): float => self::ARROW - \hypot(...self::minus(self::at($curve, $tip), self::at($curve, $t))), 0.0, $tip);
        $points = [[$start[0] - self::SPOT_BACK, $start[1]]];
        for ($step = 0; $step <= 16; ++$step) {
            $points[] = self::at($curve, ($end * $step) / 16);
        }
        $width = $style->number('LineThickness', 1.0);
        $spot = $points[0];

        return [
            new Mark(MarkKind::Polyline, $points, stroke: $color, strokeWidth: $width, dashed: null !== $style->dashes(), dashes: $style->dashes()),
            new Mark(MarkKind::Polygon, PlantUmlArrow::head(self::at($curve, $end), self::at($curve, $tip)), stroke: $color, fill: $color, strokeWidth: $width),
            DescriptionBox::ellipse($spot[0] - self::SPOT, $spot[1] - self::SPOT, 2 * self::SPOT, 2 * self::SPOT, $color, $color, 1.0),
        ];
    }

    /**
     * @param list<array{float, float}> $curve a cubic Bézier's ends and controls
     * @return array{float, float}
     */
    private static function at(array $curve, float $t): array
    {
        $u = 1 - $t;
        $weights = [$u * $u * $u, 3 * $u * $u * $t, 3 * $u * $t * $t, $t * $t * $t];

        return [
            ($weights[0] * $curve[0][0]) + ($weights[1] * $curve[1][0]) + ($weights[2] * $curve[2][0]) + ($weights[3] * $curve[3][0]),
            ($weights[0] * $curve[0][1]) + ($weights[1] * $curve[1][1]) + ($weights[2] * $curve[2][1]) + ($weights[3] * $curve[3][1]),
        ];
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @return array{float, float}
     */
    private static function minus(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1]];
    }

    /**
     * Where a function rising along the curve crosses nought, by halving; the end where it never does.
     *
     * @param \Closure(float): float $function
     */
    private static function solve(\Closure $function, float $low, float $high): float
    {
        if ($function($high) < 0) {
            return $high;
        }
        if ($function($low) > 0) {
            return $low;
        }
        for ($step = 0; $step < 40; ++$step) {
            $middle = ($low + $high) / 2;
            [$low, $high] = $function($middle) < 0 ? [$middle, $high] : [$low, $middle];
        }

        return ($low + $high) / 2;
    }
}
