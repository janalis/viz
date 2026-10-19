<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Dot\DotLayers;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Text\DisplayWidth;

/**
 * A laid-out DOT graph as a display list, drawn the way dot draws it: clusters, then edges
 * smoothed through their route and clipped at their nodes' outlines with arrowheads, then
 * nodes, then labels, with the graph's own label below it all. Laid out in points, drawn in
 * drawing units.
 */
final class DotDrawing
{
    /** @var list<Mark> marks in points */
    private array $marks = [];
    /** @var array<string, DotShape> */
    private array $shapes = [];

    private function __construct(
        private readonly DotGraph $graph,
        private readonly LayoutGraph $input,
        private readonly Placement $placement,
    ) {}

    public static function draw(DotGraph $graph, LayoutGraph $input, Placement $placement): Drawing
    {
        $drawing = new self($graph, $input, $placement);

        return $drawing->build();
    }

    private function build(): Drawing
    {
        $background = DotColors::resolve($this->graph->attribute('bgcolor'), 'none');
        foreach ($this->input->nodes as $node) {
            $endpoint = $this->placement->endpoints[$node->id];
            $this->shapes[$node->id] = DotNodes::shape($this->graph->nodes[$node->id], $endpoint->w, $endpoint->h);
        }
        $this->clusters();
        foreach ($this->graph->edges as $index => $edge) {
            $this->edge($index, $edge, 'none' === $background ? '#ffffff' : $background);
        }
        foreach ($this->placement->graph->nodes as $node) {
            $this->node($node);
        }
        $height = $this->placement->graph->height;
        $title = DotNodes::graphText($this->graph);
        if (!$title->empty()) {
            [, $titleHeight] = $title->size();
            $this->text($title, $this->placement->graph->width / 2, $height + ($titleHeight / 2));
            $height += $titleHeight + 4;
        }

        $scale = self::scale($this->graph->attribute('size'), $this->placement->graph->width, $height) * Units::POINT;

        return new Drawing(
            \max(1.0, $this->placement->graph->width * $scale),
            \max(1.0, $height * $scale),
            \array_map(static fn (Mark $mark): Mark => Units::scaled($mark, $scale), $this->marks),
            'none' === $background ? null : $background,
        );
    }

    private function clusters(): void
    {
        $boxes = [];
        foreach ($this->placement->graph->clusters as $box) {
            $boxes[$box->name] = $box;
        }
        // The layout input lists clusters as the graph's subgraphs nest, parents first.
        $subgraphs = \array_values(\array_filter($this->graph->allSubgraphs(), static fn (DotSubgraph $subgraph): bool => $subgraph->cluster()));
        foreach ($this->input->clusters as $position => $layout) {
            $box = $boxes[$layout->id];
            $cluster = $subgraphs[$position];
            $styles = self::styles($cluster->attribute('style'));
            if (\array_key_exists('invis', $styles)) {
                continue;
            }
            $color = DotColors::resolve($cluster->attribute('pencolor', $cluster->attribute('color')), '#000000');
            $fill = \array_key_exists('filled', $styles)
                ? DotColors::resolve($cluster->attribute('fillcolor', $cluster->attribute('color', $cluster->attribute('bgcolor'))), '#d3d3d3')
                : DotColors::resolve($cluster->attribute('bgcolor'), 'none');
            $this->marks[] = new Mark(
                MarkKind::Polygon,
                [[$box->x0, $box->y0], [$box->x1, $box->y0], [$box->x1, $box->y1], [$box->x0, $box->y1]],
                stroke: $color,
                fill: $fill,
                dashed: \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles),
                strokeWidth: self::penWidth($cluster->attribute('penwidth'), $styles),
                dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
            );
            $text = DotNodes::clusterText($cluster);
            if ($text->empty()) {
                continue;
            }
            [$width, $height] = $text->size();
            $x = match (\strtolower($cluster->attribute('labeljust'))) {
                'l' => $box->x0 + \Janalis\Viz\Layout\Dot\DotPosition::MARGIN + ($width / 2),
                'r' => $box->x1 - \Janalis\Viz\Layout\Dot\DotPosition::MARGIN - ($width / 2),
                default => ($box->x0 + $box->x1) / 2,
            };
            $y = 'b' === \strtolower($cluster->attribute('labelloc'))
                ? $box->y1 - \Janalis\Viz\Layout\Dot\DotPosition::MARGIN - ($height / 2)
                : $box->y0 + \Janalis\Viz\Layout\Dot\DotPosition::MARGIN + ($height / 2);
            $this->text($text, $x, $y);
        }
    }

    private function edge(int $index, DotEdge $edge, string $background): void
    {
        $styles = self::styles($edge->attribute('style'));
        if (\array_key_exists('invis', $styles)) {
            return;
        }
        $tail = $this->placement->endpoints[$edge->tail];
        $head = $this->placement->endpoints[$edge->head];
        $route = $this->placement->routes[$index] ?? null;
        // Only a loop has no route.
        $points = null === $route ? self::loop($tail) : Route::smooth($route);
        $points = $this->clip($points, $edge->tail, $tail, $edge->head, $head);
        $color = DotColors::resolve($edge->attribute('color'), '#000000');
        $penWidth = self::penWidth($edge->attribute('penwidth'), $styles);
        $size = \max(0.1, \min(10.0, DotNodes::number($edge->attribute('arrowsize'), 1.0)));
        $direction = \strtolower($edge->attribute('dir', $this->graph->directed ? 'forward' : 'none'));
        foreach ([['head', 'forward'], ['tail', 'back']] as [$end, $wanted]) {
            if ($direction !== $wanted && 'both' !== $direction) {
                continue;
            }
            $arrow = DotArrow::parse($edge->attribute('arrow'.$end, 'normal'));
            $points = 'head' === $end ? $points : \array_reverse($points);
            [$points, $tip, $along] = Route::shorten($points, $arrow->length($size));
            \array_push($this->marks, ...$arrow->marks($tip, $along, $size, $color, $background, $penWidth));
            $points = 'head' === $end ? $points : \array_reverse($points);
        }
        $this->marks[] = new Mark(
            MarkKind::Polyline,
            $points,
            stroke: $color,
            dashed: \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles),
            strokeWidth: $penWidth,
            dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
        );
        $text = DotNodes::edgeText($this->graph, $edge);
        if ($text->empty()) {
            return;
        }
        [$x, $y] = match (true) {
            \array_key_exists($index, $this->placement->labels) => $this->placement->labels[$index],
            null === $route => [$tail->x + ($tail->w / 2) + \max(DotLayers::LOOP, $tail->h / 2) + ($text->size()[0] / 2), $tail->y],
            default => $points[\intdiv(\count($points), num2: 2)],
        };
        $this->text($text, $x, $y);
    }

    private function node(GraphNode $placed): void
    {
        $node = $this->graph->nodes[$placed->name];
        $styles = self::styles($node->attribute('style'));
        if (\array_key_exists('invis', $styles)) {
            return;
        }
        $shapeName = $node->attribute('shape', 'ellipse');
        $color = DotColors::resolve($node->attribute('color'), '#000000');
        $fill = \array_key_exists('filled', $styles) || 'point' === $shapeName
            ? DotColors::resolve($node->attribute('fillcolor', $node->attribute('color')), 'point' === $shapeName ? '#000000' : '#d3d3d3')
            : 'none';
        $penWidth = self::penWidth($node->attribute('penwidth'), $styles);
        $dashed = \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles);
        if (!\in_array($shapeName, DotShape::BARE, strict: true)) {
            $shape = $this->shapes[$placed->name];
            // dot rounds plain polygons only, not those it marks.
            $outline = \array_key_exists('rounded', $styles) && [] === $shape->decorations ? self::round($shape->outline) : $shape->outline;
            $this->marks[] = new Mark(
                MarkKind::Polygon,
                self::offset($placed, $outline),
                stroke: $color,
                fill: $fill,
                dashed: $dashed,
                strokeWidth: $penWidth,
                dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
            );
            foreach ($shape->peripheries as $ring) {
                $this->marks[] = new Mark(MarkKind::Polygon, self::offset($placed, $ring), stroke: $color, fill: 'none', strokeWidth: $penWidth);
            }
            foreach ($shape->decorations as $line) {
                $this->marks[] = new Mark(MarkKind::Polyline, self::offset($placed, $line), stroke: $color, strokeWidth: $penWidth);
            }
        }
        if ('point' === $shapeName) {
            return;
        }
        $this->text(DotNodes::text($this->graph, $node), $placed->x, $placed->y);
    }

    /** Text lines centred on a point, each line set left, centred or right within the block. */
    private function text(DotText $text, float $x, float $y): void
    {
        [$width, $height] = $text->size();
        $line = Units::line($text->fontSize);
        $cell = Units::cell($text->fontSize);
        foreach ($text->lines as $index => [$content, $justify]) {
            $shown = DisplayWidth::of($content) * $cell;
            $centre = match ($justify) {
                Justify::Left => $x - ($width / 2) + ($shown / 2),
                Justify::Right => $x + ($width / 2) - ($shown / 2),
                Justify::Center => $x,
            };
            // The baseline sits a quarter line below each line's middle.
            $baseline = $y - ($height / 2) + (($index + 0.75) * $line);
            if ('' === \trim($content)) {
                continue;
            }
            $this->marks[] = new Mark(MarkKind::Text, [[$centre, $baseline]], $content, stroke: $text->color, font: $text->face, fontScale: Units::fontScale($text->fontSize) / Units::POINT);
        }
    }

    /** @param array{float, float} $point */
    private function inside(string $name, GraphNode $node, array $point): bool
    {
        if (\in_array($this->graph->nodes[$name]->attribute('shape', 'ellipse'), DotShape::BARE, strict: true)) {
            return \abs($point[0] - $node->x) < ($node->w / 2) && \abs($point[1] - $node->y) < ($node->h / 2);
        }

        return $this->shapes[$name]->contains($point[0] - $node->x, $point[1] - $node->y);
    }

    /**
     * An outline around the origin, moved onto a node's centre.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function offset(GraphNode $node, array $points): array
    {
        return \array_map(static fn (array $point): array => [$node->x + $point[0], $node->y + $point[1]], $points);
    }

    /** @return list<array{float, float}> a loop out to a node's right and back */
    private static function loop(GraphNode $node): array
    {
        $right = $node->x + ($node->w / 2);
        $reach = \max(DotLayers::LOOP, $node->h / 2);

        return Route::smooth([
            [$node->x, $node->y - ($node->h / 4)],
            [$right + ($reach / 2), $node->y - ($node->h / 2)],
            [$right + $reach, $node->y],
            [$right + ($reach / 2), $node->y + ($node->h / 2)],
            [$node->x, $node->y + ($node->h / 4)],
        ]);
    }

    /**
     * Trims a path to where it leaves its tail's outline and meets its head's.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function clip(array $points, string $tailName, GraphNode $tail, string $headName, GraphNode $head): array
    {
        $points = $this->cut($points, $tailName, $tail);
        $points = \array_reverse($this->cut(\array_reverse($points), $headName, $head));

        return \count($points) >= 2 ? $points : [$points[0] ?? [$tail->x, $tail->y], $points[0] ?? [$head->x, $head->y]];
    }

    /**
     * Drops the points at the start of a path that fall inside a node, the crossing found by bisection.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function cut(array $points, string $name, GraphNode $node): array
    {
        $count = \count($points);
        $index = 0;
        while ($index < $count && $this->inside($name, $node, $points[$index])) {
            ++$index;
        }
        if (0 === $index || $count === $index) {
            return $points;
        }
        [$in, $out] = [$points[$index - 1], $points[$index]];
        for ($step = 0; $step < 12; ++$step) {
            $middle = [($in[0] + $out[0]) / 2, ($in[1] + $out[1]) / 2];
            [$in, $out] = $this->inside($name, $node, $middle) ? [$middle, $out] : [$in, $middle];
        }

        return [$out, ...\array_slice($points, $index)];
    }

    /**
     * Rounds a polygon's corners.
     *
     * @param list<array{float, float}> $outline
     * @return list<array{float, float}>
     */
    private static function round(array $outline): array
    {
        $rounded = [];
        $count = \count($outline);
        foreach ($outline as $index => [$x, $y]) {
            [$px, $py] = $outline[($index + $count - 1) % $count];
            [$nx, $ny] = $outline[($index + 1) % $count];
            $radius = \min(8.0, \hypot($x - $px, $y - $py) / 3, \hypot($nx - $x, $ny - $y) / 3);
            $from = self::toward([$x, $y], [$px, $py], $radius);
            $to = self::toward([$x, $y], [$nx, $ny], $radius);
            foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $t) {
                $u = 1 - $t;
                $rounded[] = [($u * $u * $from[0]) + (2 * $u * $t * $x) + ($t * $t * $to[0]), ($u * $u * $from[1]) + (2 * $u * $t * $y) + ($t * $t * $to[1])];
            }
        }

        return $rounded;
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function toward(array $from, array $to, float $distance): array
    {
        $length = \hypot($to[0] - $from[0], $to[1] - $from[1]);
        $share = $length > 0 ? $distance / $length : 0.0;

        return [$from[0] + (($to[0] - $from[0]) * $share), $from[1] + (($to[1] - $from[1]) * $share)];
    }

    /** @return array<string, true> */
    private static function styles(string $style): array
    {
        $styles = [];
        foreach (\explode(',', $style) as $part) {
            $styles[\strtolower(\trim($part))] = true;
        }

        return $styles;
    }

    /** @param array<string, true> $styles */
    private static function penWidth(string $value, array $styles): float
    {
        $width = \is_numeric($value) ? \max(0.0, \min(50.0, (float) $value)) : 1.0;

        return \array_key_exists('bold', $styles) ? \max($width, 2.0) : $width;
    }

    /**
     * How much `size` shrinks a drawing, in inches as `width,height`: down to fit, and up to
     * fill when it ends in `!`.
     */
    private static function scale(string $size, float $width, float $height): float
    {
        $parts = \explode(',', \rtrim($size, characters: '!'));
        $limitWidth = DotNodes::number(\trim($parts[0]), 0.0) * DotLayoutInput::POINTS;
        $limitHeight = DotNodes::number(\trim($parts[1] ?? $parts[0]), 0.0) * DotLayoutInput::POINTS;
        if ($limitWidth <= 0 || $limitHeight <= 0 || $width <= 0 || $height <= 0) {
            return 1.0;
        }
        $fit = \min($limitWidth / $width, $limitHeight / $height);

        return \str_ends_with($size, '!') ? $fit : \min(1.0, $fit);
    }
}
