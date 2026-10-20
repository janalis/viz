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
    /** @var array<string, ?DotRecord> each record node's fields, read once */
    private array $records = [];
    /** @var array<int, array{?array{float, float}, ?array{float, float}}> where each edge sharing a `sametail` or a `samehead` meets the others, by edge index */
    private array $meets = [];
    /** @var list<array{float, float, float, float}> the boxes of labels drawn outside what was laid out, which the drawing grows to hold */
    private array $outside = [];
    /** @var list<array{float, float, float, float}> the boxes of the labels drawn so far, which an external label keeps clear of */
    private array $taken = [];

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
        [$background, $gradient] = DotColors::fill($this->graph->attribute('bgcolor'), 'none', $this->graph->attribute('gradientangle'));
        foreach ($this->input->nodes as $node) {
            $endpoint = $this->placement->endpoints[$node->id];
            $this->shapes[$node->id] = DotNodes::shape($this->graph->nodes[$node->id], $endpoint->w, $endpoint->h);
        }
        $this->clusters();
        $splines = \strtolower($this->graph->attribute('splines', 'true'));
        // As dot, `splines=none`, or set empty, draws no edges at all.
        $edges = \in_array($splines, ['', 'none'], strict: true) ? [] : $this->graph->edges;
        $this->meet($edges, $splines);
        foreach ($edges as $index => $edge) {
            $this->edge($index, $edge, 'none' === $background ? '#ffffff' : $background, $splines);
        }
        foreach ($this->placement->graph->nodes as $node) {
            $this->node($node);
        }
        [$width, $height] = $this->grown();
        $title = DotNodes::graphText($this->graph);
        if (!$title->empty()) {
            [, $titleHeight] = $title->size();
            $this->text($title, $width / 2, $height + ($titleHeight / 2));
            $height += $titleHeight + 4;
        }
        // The layout pads the drawing by dot's default 4 points; `pad` asks for more or less.
        [$padX, $padY] = self::pad($this->graph->attribute('pad'));
        if (4.0 !== $padX || 4.0 !== $padY) {
            $this->marks = \array_map(static fn (Mark $mark): Mark => Units::moved($mark, $padX - 4.0, $padY - 4.0), $this->marks);
            $width += 2 * ($padX - 4.0);
            $height += 2 * ($padY - 4.0);
        }
        if (null !== $gradient) {
            // A gradient background is a box behind everything, a plain one the drawing's own.
            \array_unshift($this->marks, new Mark(MarkKind::Polygon, [[0.0, 0.0], [$width, 0.0], [$width, $height], [0.0, $height]], stroke: 'none', fill: $background, gradient: $gradient));
            $background = 'none';
        }

        $scale = self::scale($this->graph->attribute('size'), $width, $height) * Units::POINT;

        return new Drawing(
            \max(1.0, $width * $scale),
            \max(1.0, $height * $scale),
            \array_map(static fn (Mark $mark): Mark => Units::scaled($mark, $scale), $this->marks),
            'none' === $background ? null : $background,
        );
    }

    /**
     * The drawing grown to hold the labels drawn outside what was laid out, everything moved
     * clear of the top and left.
     *
     * @return array{float, float} its width and height
     */
    private function grown(): array
    {
        $boxes = [[0.0, 0.0, $this->placement->graph->width, $this->placement->graph->height], ...$this->outside];
        $left = \min(\array_column($boxes, 0));
        $top = \min(\array_column($boxes, 1));
        if ($left < 0 || $top < 0) {
            $this->marks = \array_map(static fn (Mark $mark): Mark => Units::moved($mark, -$left, -$top), $this->marks);
        }

        return [\max(\array_column($boxes, 2)) - $left, \max(\array_column($boxes, 3)) - $top];
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
            [$fill, $gradient] = \array_key_exists('filled', $styles)
                ? DotColors::fill($cluster->attribute('fillcolor', $cluster->attribute('color', $cluster->attribute('bgcolor'))), '#d3d3d3', $cluster->attribute('gradientangle'))
                : DotColors::fill($cluster->attribute('bgcolor'), 'none', $cluster->attribute('gradientangle'));
            $this->marks[] = new Mark(
                MarkKind::Polygon,
                [[$box->x0, $box->y0], [$box->x1, $box->y0], [$box->x1, $box->y1], [$box->x0, $box->y1]],
                stroke: $color,
                fill: $fill,
                dashed: \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles),
                strokeWidth: self::penWidth($cluster->attribute('penwidth'), $styles),
                gradient: $gradient,
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

    /** @param string $splines how edges are drawn: `line` or `false` straight, `polyline` corner to corner, else curved */
    private function edge(int $index, DotEdge $edge, string $background, string $splines): void
    {
        $styles = self::styles($edge->attribute('style'));
        if (\array_key_exists('invis', $styles)) {
            return;
        }
        $tail = $this->placement->endpoints[$edge->tail];
        $route = $this->placement->routes[$index] ?? null;
        $points = $this->path($index, $edge, $splines);
        [$tailMeets, $headMeets] = $this->meets[$index] ?? [null, null];
        if (null !== $tailMeets || null !== $headMeets) {
            $points = Route::eased($points, $tailMeets ?? $points[0], $headMeets ?? $points[\count($points) - 1]);
        }
        $this->endLabels($edge, $points);
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
        $external = DotNodes::externalText($this->graph, $edge);
        if (!$external->empty()) {
            // As dot, an edge's external label stands by its middle, above and left where it is clear.
            [$middleX, $middleY] = $points[\intdiv(\count($points), num2: 2)];
            $this->external($external, [$middleX, $middleY, $middleX, $middleY]);
        }
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
        if (\in_array(\strtolower($edge->attribute('decorate')), ['true', 'yes', '1'], strict: true)) {
            // As dot, a decorated label is underlined, from its far end to its near one, then joined to the edge's middle.
            [$width, $height] = $text->size();
            [$middleX, $middleY] = $points[\intdiv(\count($points), num2: 2)];
            $bottom = $y + ($height / 2);
            $side = $middleX < $x ? -1 : 1;
            $this->marks[] = new Mark(
                MarkKind::Polyline,
                [[$x - (($side * $width) / 2), $bottom], [$x + (($side * $width) / 2), $bottom], [$middleX, $middleY]],
                stroke: $color,
                strokeWidth: $penWidth,
            );
        }
    }

    /**
     * An edge's path, clipped at its ends' outlines: a loop's on its node's right; else its
     * spline, its corners, or a straight line, as `splines` asks, bent to any port.
     *
     * @return list<array{float, float}>
     */
    private function path(int $index, DotEdge $edge, string $splines): array
    {
        $tail = $this->placement->endpoints[$edge->tail];
        $head = $this->placement->endpoints[$edge->head];
        $route = $this->placement->routes[$index] ?? null;
        $spline = $this->placement->splines[$index] ?? null;
        $polyline = $this->placement->polylines[$index] ?? $route;
        // Only a loop has no route; an edge between ranks has its spline, bent to any port.
        $points = match (true) {
            null === $route => self::loop($tail),
            \in_array($splines, ['line', 'false'], strict: true) => $this->ported([$route[0], $route[\count($route) - 1]], $edge),
            'polyline' === $splines && null !== $polyline => $this->bent($polyline, $edge),
            null !== $spline => $this->bent($spline, $edge),
            default => Route::smooth($this->ported($route, $edge)),
        };

        return $this->clip($points, $edge, $tail, $head);
    }

    /**
     * Where edges sharing a `samehead` at their head, or a `sametail` at their tail, meet: on
     * the node's outline, toward where their ends would fall on their own, on average.
     *
     * @param array<int, DotEdge> $edges
     */
    private function meet(array $edges, string $splines): void
    {
        $groups = [];
        foreach ($edges as $index => $edge) {
            foreach ([['sametail', $edge->tail, 0], ['samehead', $edge->head, 1]] as [$attribute, $node, $end]) {
                $value = $edge->attribute($attribute);
                if ('' === $value) {
                    continue;
                }
                $groups[$attribute."\0".$node."\0".$value] ??= [$node, []];
                $groups[$attribute."\0".$node."\0".$value][1][] = [$index, $end];
            }
        }
        foreach ($groups as [$name, $members]) {
            $ends = [];
            foreach ($members as [$index, $end]) {
                $points = $this->path($index, $edges[$index], $splines);
                $ends[] = 0 === $end ? $points[0] : $points[\count($points) - 1];
            }
            $node = $this->placement->endpoints[$name];
            [$x, $y] = [\array_sum(\array_column($ends, 0)) / \count($ends), \array_sum(\array_column($ends, 1)) / \count($ends)];
            $length = \hypot($x - $node->x, $y - $node->y);
            $reach = $node->w + $node->h;
            // From the centre out through their mean, to the outline.
            $far = $length > 0 ? [$node->x + ((($x - $node->x) * $reach) / $length), $node->y + ((($y - $node->y) * $reach) / $length)] : [$node->x, $node->y + $reach];
            [$meets] = $this->cut([[$node->x, $node->y], $far], $name, $node, null);
            foreach ($members as [$index, $end]) {
                $this->meets[$index] ??= [null, null];
                $this->meets[$index][$end] = $meets;
            }
        }
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
        [$fill, $gradient] = \array_key_exists('filled', $styles) || 'point' === $shapeName
            ? DotColors::fill($node->attribute('fillcolor', $node->attribute('color')), 'point' === $shapeName ? '#000000' : '#d3d3d3', $node->attribute('gradientangle'))
            : ['none', null];
        $penWidth = self::penWidth($node->attribute('penwidth'), $styles);
        $dashed = \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles);
        if (!\in_array($shapeName, DotShape::BARE, strict: true)) {
            $shape = $this->shapes[$placed->name];
            // dot rounds plain polygons only, not those it marks, and an Mrecord always.
            $rounded = (\array_key_exists('rounded', $styles) && [] === $shape->decorations) || 'Mrecord' === $shapeName;
            $outline = $rounded ? self::round($shape->outline) : $shape->outline;
            $this->marks[] = new Mark(
                MarkKind::Polygon,
                self::offset($placed, $outline),
                stroke: $color,
                fill: $fill,
                dashed: $dashed,
                strokeWidth: $penWidth,
                gradient: $gradient,
                dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
            );
            foreach ($shape->peripheries as $ring) {
                $this->marks[] = new Mark(MarkKind::Polygon, self::offset($placed, $ring), stroke: $color, fill: 'none', strokeWidth: $penWidth);
            }
            foreach ($shape->decorations as $line) {
                $this->marks[] = new Mark(MarkKind::Polyline, self::offset($placed, $line), stroke: $color, strokeWidth: $penWidth);
            }
        }
        $external = DotNodes::externalText($this->graph, $node);
        if (!$external->empty()) {
            // As dot, a node's external label stands off a corner of the node, its upper left where it is clear.
            $this->external($external, self::box($placed));
        }
        if ('point' === $shapeName) {
            return;
        }
        $record = $this->record($placed->name);
        if (null === $record) {
            // As dot, a record whose label is not a record's shows its name.
            $text = DotNodes::isRecord($node) && !\array_key_exists('label', $node->html) ? DotText::parse($node->name, []) : DotNodes::text($this->graph, $node);
            $this->text($text, $placed->x, $placed->y);

            return;
        }
        $box = self::box($placed);
        foreach ($record->separators($box) as $line) {
            $this->marks[] = new Mark(MarkKind::Polyline, $line, stroke: $color, strokeWidth: $penWidth);
        }
        foreach ($record->cells($box) as [$text, [$left, $top, $right, $bottom]]) {
            $this->text($text, ($left + $right) / 2, ($top + $bottom) / 2);
        }
    }

    /**
     * An edge's head and tail labels by its ends. Where `labelangle` or `labeldistance` is set,
     * as DOT documents: 10 points times the distance from the end, turned by the angle, in
     * degrees counter-clockwise, from the edge's direction there. Otherwise as dot sets them
     * by default: beside the end, right of the way the edge runs, clear of it by half the label.
     *
     * @param list<array{float, float}> $points the edge's path, tail to head, clipped at its ends
     */
    private function endLabels(DotEdge $edge, array $points): void
    {
        $explicit = '' !== $edge->attribute('labelangle') || '' !== $edge->attribute('labeldistance');
        $angle = \deg2rad(DotNodes::number($edge->attribute('labelangle'), -25.0));
        $distance = 10 * \max(0.0, \min(100.0, DotNodes::number($edge->attribute('labeldistance'), 1.0)));
        foreach (['headlabel' => [\array_reverse($points), -1.0], 'taillabel' => [$points, 1.0]] as $attribute => [$path, $travel]) {
            $text = DotNodes::endText($this->graph, $edge, $attribute);
            if ($text->empty()) {
                continue;
            }
            [$x, $y] = $path[0];
            [$intoX, $intoY] = self::direction($path);
            if ($explicit) {
                $this->beside($text, $x + ($distance * (($intoX * \cos($angle)) + ($intoY * \sin($angle)))), $y + ($distance * (($intoY * \cos($angle)) - ($intoX * \sin($angle)))));
                continue;
            }
            [$width, $height] = $text->size();
            // Right of the way the edge runs: from tail to head, which at the head is back out of the edge.
            [$rightX, $rightY] = [-$intoY * $travel, $intoX * $travel];
            $across = ((\abs($rightX) * $width) + (\abs($rightY) * $height)) / 2;
            $along = ((\abs($intoX) * $width) + (\abs($intoY) * $height)) / 2;
            $this->beside($text, $x + ($rightX * $across) + ($intoX * $along), $y + ($rightY * $across) + ($intoY * $along));
        }
    }

    /**
     * The direction a path runs from its first point, as a unit vector; down where it does not move.
     *
     * @param list<array{float, float}> $path
     * @return array{float, float}
     */
    private static function direction(array $path): array
    {
        [$x, $y] = $path[0];
        [$nextX, $nextY] = \array_find($path, static fn (array $point): bool => \hypot($point[0] - $x, $point[1] - $y) > 0.01) ?? [$x, $y + 1];
        $length = \hypot($nextX - $x, $nextY - $y);

        return [($nextX - $x) / $length, ($nextY - $y) / $length];
    }

    /**
     * An external label off a corner of a box: above and left, above and right, below and left,
     * or below and right, the first clear of the nodes and the labels drawn so far.
     *
     * @param array{float, float, float, float} $box
     */
    private function external(DotLabel $label, array $box): void
    {
        [$width, $height] = $label->size();
        [$left, $top, $right, $bottom] = $box;
        $corners = [
            [$left - ($width / 2), $top - ($height / 2)],
            [$right + ($width / 2), $top - ($height / 2)],
            [$left - ($width / 2), $bottom + ($height / 2)],
            [$right + ($width / 2), $bottom + ($height / 2)],
        ];
        $boxes = [...\array_map(self::box(...), \array_values($this->placement->graph->nodes)), ...$this->taken];
        foreach ($corners as [$x, $y]) {
            $clear = [] === \array_filter($boxes, static fn (array $other): bool => self::overlap([$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)], $other));
            if ($clear) {
                $this->beside($label, $x, $y);

                return;
            }
        }
        $this->beside($label, ...$corners[0]);
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function overlap(array $a, array $b): bool
    {
        return $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
    }

    /** A label centred on a point outside what was laid out: the drawing grows to hold it. */
    private function beside(DotLabel $label, float $x, float $y): void
    {
        [$width, $height] = $label->size();
        $this->outside[] = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
        $this->text($label, $x, $y);
    }

    /** A label centred on a point. */
    private function text(DotLabel $label, float $x, float $y): void
    {
        [$width, $height] = $label->size();
        $this->taken[] = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
        \array_push($this->marks, ...$label->marks($x, $y));
    }

    /** A record node's fields, read once however many edges reach it; null for any other node. */
    private function record(string $name): ?DotRecord
    {
        if (!\array_key_exists($name, $this->records)) {
            $this->records[$name] = DotNodes::record($this->graph, $this->graph->nodes[$name]);
        }

        return $this->records[$name];
    }

    /** @return array{float, float, float, float} a node's box: left, top, right, bottom */
    private static function box(GraphNode $node): array
    {
        return [$node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2)];
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
     * A spline bent to start and end at its edge's ports: each end moved to its port, the move
     * easing out along the curve, so it still leaves and reaches its ends the way it did.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function bent(array $points, DotEdge $edge): array
    {
        $tail = $this->anchor($edge->tail, $edge->attribute('tailport'))[0] ?? $points[0];
        $head = $this->anchor($edge->head, $edge->attribute('headport'))[0] ?? $points[\count($points) - 1];

        return Route::eased($points, $tail, $head);
    }

    /**
     * A path moved to start and end at its edge's ports: a record's field, at its centre, or
     * a compass point on the side of the node, or of the field, it names.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function ported(array $points, DotEdge $edge): array
    {
        $tail = $this->anchor($edge->tail, $edge->attribute('tailport'));
        $head = $this->anchor($edge->head, $edge->attribute('headport'));
        $last = \count($points) - 1;
        $points[0] = $tail[0] ?? $points[0];
        $points[$last] = $head[0] ?? $points[$last];

        return $points;
    }

    /**
     * Where a port puts an edge's end, and the box it is clipped at: a record field's, which
     * draws no fill to hide it. An HTML cell's port aims the end at the cell and clips it at the
     * node's outline, so no cell drawn over the edge hides its arrowhead.
     *
     * @return array{?array{float, float}, ?array{float, float, float, float}}
     */
    private function anchor(string $name, string $port): array
    {
        $record = $this->record($name);
        [$point, $field] = DotPorts::anchor($this->graph, $this->graph->nodes[$name], $record, $port, self::box($this->placement->endpoints[$name]));

        return [$point, null === $record ? null : $field];
    }

    /**
     * Trims a path to where it leaves its tail's outline, or its port's field, and meets its head's.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function clip(array $points, DotEdge $edge, GraphNode $tail, GraphNode $head): array
    {
        if ('false' !== \strtolower($edge->attribute('tailclip'))) {
            $points = $this->cut($points, $edge->tail, $tail, $this->compound($edge->attribute('ltail'), $head) ?? $this->anchor($edge->tail, $edge->attribute('tailport'))[1]);
        }
        if ('false' !== \strtolower($edge->attribute('headclip'))) {
            $points = \array_reverse($this->cut(
                \array_reverse($points),
                $edge->head,
                $head,
                $this->compound($edge->attribute('lhead'), $tail) ?? $this->anchor($edge->head, $edge->attribute('headport'))[1],
            ));
        }

        return \count($points) >= 2 ? $points : [$points[0] ?? [$tail->x, $tail->y], $points[0] ?? [$head->x, $head->y]];
    }

    /**
     * The box of the cluster an edge's `lhead` or `ltail` names, which it is clipped at, as dot
     * does in a `compound` graph; null when the graph is not compound, no cluster has the name,
     * or the edge's other end stands inside it.
     *
     * @return ?array{float, float, float, float}
     */
    private function compound(string $cluster, GraphNode $other): ?array
    {
        if ('true' !== \strtolower($this->graph->attribute('compound')) || '' === $cluster) {
            return null;
        }
        foreach ($this->placement->graph->clusters as $box) {
            if ($box->name !== $cluster) {
                continue;
            }
            $inside = $other->x > $box->x0 && $other->x < $box->x1 && $other->y > $box->y0 && $other->y < $box->y1;

            return $inside ? null : [$box->x0, $box->y0, $box->x1, $box->y1];
        }

        return null;
    }

    /**
     * Whether a point falls inside where an edge's end is clipped: its port's field, or its node's outline.
     *
     * @param ?array{float, float, float, float} $field
     * @param array{float, float} $point
     */
    private function within(string $name, GraphNode $node, ?array $field, array $point): bool
    {
        if (null === $field) {
            return $this->inside($name, $node, $point);
        }
        [$left, $top, $right, $bottom] = $field;

        return $point[0] > $left && $point[0] < $right && $point[1] > $top && $point[1] < $bottom;
    }

    /**
     * Drops the points at the start of a path that fall inside a node, or its port's field, the crossing found by bisection.
     *
     * @param list<array{float, float}> $points
     * @param ?array{float, float, float, float} $field the port's field the end is clipped at, if any
     * @return list<array{float, float}>
     */
    private function cut(array $points, string $name, GraphNode $node, ?array $field): array
    {
        $count = \count($points);
        $index = 0;
        while ($index < $count && $this->within($name, $node, $field, $points[$index])) {
            ++$index;
        }
        if (0 === $index || $count === $index) {
            return $points;
        }
        [$in, $out] = [$points[$index - 1], $points[$index]];
        for ($step = 0; $step < 12; ++$step) {
            $middle = [($in[0] + $out[0]) / 2, ($in[1] + $out[1]) / 2];
            [$in, $out] = $this->within($name, $node, $field, $middle) ? [$middle, $out] : [$in, $middle];
        }

        return [$out, ...\array_slice($points, $index)];
    }

    /**
     * Rounds a polygon's corners, each as far as a third of its shorter side, and at most as `$most`.
     *
     * @param list<array{float, float}> $outline
     * @return list<array{float, float}>
     */
    public static function round(array $outline, float $most = 8.0): array
    {
        $rounded = [];
        $count = \count($outline);
        foreach ($outline as $index => [$x, $y]) {
            [$px, $py] = $outline[($index + $count - 1) % $count];
            [$nx, $ny] = $outline[($index + 1) % $count];
            $radius = \min($most, \hypot($x - $px, $y - $py) / 3, \hypot($nx - $x, $ny - $y) / 3);
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
    /**
     * The `pad` around the drawing, across and down, in points: one length in inches for both, or two.
     *
     * @return array{float, float}
     */
    private static function pad(string $pad): array
    {
        $parts = \explode(',', $pad);
        $inches = static fn (string $value): float => \max(0.0, \min(DotLayoutInput::MAX_INCHES, DotNodes::number(\trim($value), 4.0 / DotLayoutInput::POINTS)));

        return [$inches($parts[0]) * DotLayoutInput::POINTS, $inches($parts[1] ?? $parts[0]) * DotLayoutInput::POINTS];
    }

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
