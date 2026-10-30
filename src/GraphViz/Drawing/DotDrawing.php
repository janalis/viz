<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Draw\Shading;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Dot\DotEndLabels;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/**
 * A laid-out DOT graph as a display list, drawn the way dot draws it: clusters, then edges
 * along their paths ({@see DotEdgePaths}) with arrowheads, then
 * nodes, then labels, with the graph's own label below it all. Laid out in points, drawn in
 * drawing units.
 */
final class DotDrawing
{
    /** @var list<Mark> marks in points */
    private array $marks = [];
    /** @var array<string, DotShape> */
    private array $shapes = [];
    private DotEdgePaths $paths;
    /** @var array<int, array{?array{float, float}, ?array{float, float}}> where each edge sharing a `sametail` or a `samehead` meets the others, by edge index */
    private array $meets = [];
    /** @var list<array{float, float, float, float}> the boxes of labels drawn outside what was laid out, which the drawing grows to hold */
    private array $outside = [];
    /** @var list<array{float, float, float, float}> the boxes of the labels drawn so far, which an external label keeps clear of */
    private array $taken = [];
    /** @var array<int, array<string, array{float, float}>> where each edge's head and tail labels stand by default, centred, by edge index and attribute */
    private array $ends = [];

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
        [$background, $gradient, $split] = DotColors::fill($this->graph->attribute('bgcolor'), 'none');
        foreach ($this->input->nodes as $node) {
            $endpoint = $this->placement->endpoints[$node->id];
            $this->shapes[$node->id] = DotNodes::shape($this->graph->nodes[$node->id], $endpoint->w, $endpoint->h);
        }
        $this->clusters();
        $splines = \strtolower($this->graph->attribute('splines', 'true'));
        // As dot, `splines=none`, or set empty, draws no edges, nor does patchwork, its nodes all touching.
        $edges = \in_array($splines, ['', 'none'], strict: true) || 'patchwork' === \strtolower($this->graph->attribute('layout')) ? [] : $this->graph->edges;
        $this->paths = new DotEdgePaths($this->graph, $this->placement, $this->shapes);
        $this->meets = $this->paths->meet($edges, $splines);
        $this->ends = $this->endPlaces($edges, $splines);
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
            \array_unshift(
                $this->marks,
                new Mark(
                    MarkKind::Polygon,
                    [[0.0, 0.0], [$width, 0.0], [$width, $height], [0.0, $height]],
                    stroke: 'none',
                    fill: $background,
                    gradient: $gradient,
                    gradientAngle: DotColors::angle($this->graph->attribute('gradientangle')),
                    gradientSplit: $split,
                ),
            );
            $background = 'none';
        }
        if ($this->landscape()) {
            $this->marks = \array_map(static fn (Mark $mark): Mark => Units::turned($mark, $width), $this->marks);
            [$width, $height] = [$height, $width];
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
            $box = $boxes[$layout->id] ?? null;
            // A layout that places no clusters, as neato, draws none.
            if (null === $box) {
                continue;
            }
            $cluster = $subgraphs[$position];
            $styles = self::styles($cluster->attribute('style'));
            if (\array_key_exists('invis', $styles)) {
                continue;
            }
            $color = DotColors::resolve($cluster->attribute('pencolor', $cluster->attribute('color')), '#000000');
            [$fill, $gradient, $split] = \array_key_exists('filled', $styles) || \array_key_exists('radial', $styles)
                ? DotColors::fill($cluster->attribute('fillcolor', $cluster->attribute('color', $cluster->attribute('bgcolor'))), '#d3d3d3')
                : DotColors::fill($cluster->attribute('bgcolor'), 'none');
            $pattern = DotFills::pattern($styles, 'box', [$box->x0, $box->y0, $box->x1, $box->y1], $cluster->attribute('fillcolor', $cluster->attribute('color')), $color);
            \array_push($this->marks, ...$pattern);
            [$fill, $gradient] = [] === $pattern ? [$fill, $gradient] : ['none', null];
            $this->marks[] = new Mark(
                MarkKind::Polygon,
                [[$box->x0, $box->y0], [$box->x1, $box->y0], [$box->x1, $box->y1], [$box->x0, $box->y1]],
                stroke: $color,
                fill: $fill,
                dashed: \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles),
                strokeWidth: self::penWidth($cluster->attribute('penwidth'), $styles),
                gradient: $gradient,
                dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
                shading: self::shading($styles),
                gradientAngle: DotColors::angle($cluster->attribute('gradientangle')),
                gradientSplit: $split,
            );
            $text = DotNodes::clusterText($cluster);
            // patchwork labels no clusters: its nodes fill them.
            if ($text->empty() || 'patchwork' === \strtolower($this->graph->attribute('layout'))) {
                continue;
            }
            [$width, $height] = $text->size();
            $x = match (\strtolower($cluster->attribute('labeljust'))) {
                'l' => $box->x0 + \Janalis\Viz\Layout\Dot\DotPosition::MARGIN + ($width / 2),
                'r' => $box->x1 - \Janalis\Viz\Layout\Dot\DotPosition::MARGIN - ($width / 2),
                default => ($box->x0 + $box->x1) / 2,
            };
            // As `dot` is seen to set it: across, 8 in from the side it is justified to; down, in the middle of its band, 4 in.
            $y = 'b' === \strtolower($cluster->attribute('labelloc'))
                ? $box->y1 - (\Janalis\Viz\Layout\Dot\DotPosition::MARGIN / 2) - ($height / 2)
                : $box->y0 + (\Janalis\Viz\Layout\Dot\DotPosition::MARGIN / 2) + ($height / 2);
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
        $pieces = $this->pieces($index, $edge, $splines);
        if ([] === $pieces) {
            return;
        }
        $this->endLabels($index, $edge, self::joined($pieces));
        $colors = DotStrokes::ends($edge->attribute('color'));
        $penWidth = self::penWidth($edge->attribute('penwidth'), $styles);
        $size = \max(0.1, \min(10.0, DotNodes::number($edge->attribute('arrowsize'), 1.0)));
        foreach ($this->cut($edge, $pieces, $size, $penWidth) as [$end, $arrow, $tip, $along]) {
            \array_push($this->marks, ...$arrow->marks($tip, $along, $size, $colors['head' === $end ? 1 : 0], $background, $penWidth));
        }
        foreach ($pieces as [$piece]) {
            foreach (DotStrokes::lines($piece, $edge->attribute('color')) as [$line, $stroke]) {
                $this->marks[] = new Mark(
                    MarkKind::Polyline,
                    $line,
                    stroke: $stroke,
                    dashed: \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles),
                    strokeWidth: $penWidth,
                    dashes: \array_key_exists('dotted', $styles) ? [1.0, 3.0] : null,
                );
            }
        }
        $points = self::joined($pieces);
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
            null === $route => [$tail->x + $this->paths->loopReach($index)[0] + ($text->size()[0] / 2), $tail->y],
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
                stroke: $colors[0],
                strokeWidth: $penWidth,
            );
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
        [$fill, $gradient, $split] =
            \array_key_exists('filled', $styles) || \array_key_exists('radial', $styles) || 'point' === $shapeName
                ? DotColors::fill($node->attribute('fillcolor', $node->attribute('color')), 'point' === $shapeName ? '#000000' : '#d3d3d3')
                : ['none', null, null];
        $penWidth = self::penWidth($node->attribute('penwidth'), $styles);
        $dashed = \array_key_exists('dashed', $styles) || \array_key_exists('dotted', $styles);
        $pattern = DotFills::pattern($styles, $shapeName, DotEdgePaths::box($placed), $node->attribute('fillcolor', $node->attribute('color')), $color);
        \array_push($this->marks, ...$pattern);
        [$fill, $gradient] = [] === $pattern ? [$fill, $gradient] : ['none', null];
        if (!\in_array($shapeName, DotShape::BARE, strict: true)) {
            $shape = $this->shapes[$placed->name];
            // dot rounds plain polygons only, not those it marks or stripes, and an Mrecord always.
            $rounded = (\array_key_exists('rounded', $styles) && [] === $shape->decorations && [] === $pattern) || 'Mrecord' === $shapeName;
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
                shading: self::shading($styles),
                gradientAngle: DotColors::angle($node->attribute('gradientangle')),
                gradientSplit: $split,
            );
            foreach ($shape->peripheries as $ring) {
                $this->marks[] = new Mark(MarkKind::Polygon, self::offset($placed, $ring), stroke: $color, fill: 'none', strokeWidth: $penWidth);
            }
            foreach ($shape->decorations as $line) {
                $this->marks[] = new Mark(MarkKind::Polyline, self::offset($placed, $line), stroke: $color, strokeWidth: $penWidth);
            }
        }
        $image = $this->graph->images[$node->attribute('image')] ?? null;
        if (null !== $image) {
            \array_push($this->marks, ...DotPictures::marks($image, DotEdgePaths::box($placed), $node->attribute('imagescale'), $node->attribute('imagepos')));
        }
        $external = DotNodes::externalText($this->graph, $node);
        if (!$external->empty()) {
            // As dot, a node's external label stands off a corner of the node, its upper left where it is clear.
            $this->external($external, DotEdgePaths::box($placed));
        }
        if ('point' === $shapeName) {
            return;
        }
        $record = $this->paths->record($placed->name);
        if (null === $record) {
            // As dot, a record whose label is not a record's shows its name.
            $text = DotNodes::isRecord($node) && !\array_key_exists('label', $node->html) ? DotText::parse($node->name, []) : DotNodes::text($this->graph, $node);
            $this->text($text, $placed->x, $placed->y);

            return;
        }
        $box = DotEdgePaths::box($placed);
        foreach ($record->separators($box) as $line) {
            $this->marks[] = new Mark(MarkKind::Polyline, $line, stroke: $color, strokeWidth: $penWidth);
        }
        foreach ($record->cells($box) as [$text, [$left, $top, $right, $bottom]]) {
            $this->text($text, ($left + $right) / 2, ($top + $bottom) / 2);
        }
    }

    /**
     * An edge's pieces as drawn: an edge merged with others only its own parts, one wholly drawn
     * by them none; eased to where it meets those sharing its `sametail` or `samehead`.
     *
     * @return list<array{list<array{float, float}>, bool, bool}>
     */
    private function pieces(int $index, DotEdge $edge, string $splines): array
    {
        $pieces = $this->paths->pieces($index, $edge, $splines);
        [$tailMeets, $headMeets] = $this->meets[$index] ?? [null, null];
        if ([] !== $pieces && (null !== $tailMeets || null !== $headMeets)) {
            $only = $pieces[0][0];
            $pieces[0][0] = Route::eased($only, $tailMeets ?? $only[0], $headMeets ?? $only[\count($only) - 1]);
        }

        return $pieces;
    }

    /**
     * An edge's arrowheads, its pieces cut back to make room for them: for each end that has
     * one, which end, the arrow, its tip, and the way it points.
     *
     * @param list<array{list<array{float, float}>, bool, bool}> $pieces
     * @return list<array{string, DotArrow, array{float, float}, array{float, float}}>
     */
    private function cut(DotEdge $edge, array &$pieces, float $size, float $penWidth): array
    {
        $direction = \strtolower($edge->attribute('dir', $this->graph->directed ? 'forward' : 'none'));
        $arrows = [];
        foreach ([['head', 'forward', \count($pieces) - 1, 2], ['tail', 'back', 0, 1]] as [$end, $wanted, $at, $reaches]) {
            if (($direction !== $wanted && 'both' !== $direction) || !$pieces[$at][$reaches]) {
                continue;
            }
            $arrow = DotArrow::parse($edge->attribute('arrow'.$end, 'normal'));
            $line = 'head' === $end ? $pieces[$at][0] : \array_reverse($pieces[$at][0]);
            [$line, $tip, $along] = Route::shorten($line, $arrow->length($size, $penWidth));
            $arrows[] = [$end, $arrow, $tip, $along];
            $pieces[$at][0] = 'head' === $end ? $line : \array_reverse($line);
        }

        return $arrows;
    }

    /**
     * Where the edges' head and tail labels stand by default, as `DotEndLabels` sets them from
     * where their lines end, short of any arrowhead, each clear where it can be of the nodes,
     * the edges' labels and the end labels set before it.
     *
     * @param array<int, DotEdge> $edges
     * @return array<int, array<string, array{float, float}>> each label's centre, by edge index and attribute
     */
    private function endPlaces(array $edges, string $splines): array
    {
        $obstacles = \array_values(\array_map(DotEdgePaths::box(...), $this->placement->graph->nodes));
        [$labels, $owners] = [[], []];
        foreach ($edges as $index => $edge) {
            $text = DotNodes::edgeText($this->graph, $edge);
            if (\array_key_exists($index, $this->placement->labels) && !$text->empty()) {
                [$x, $y] = $this->placement->labels[$index];
                [$width, $height] = $text->size();
                $obstacles[] = [$x - ($width / 2), $y - ($height / 2), $x + ($width / 2), $y + ($height / 2)];
            }
            $explicit = '' !== $edge->attribute('labelangle') || '' !== $edge->attribute('labeldistance');
            $pieces = \array_key_exists('invis', self::styles($edge->attribute('style'))) || $explicit ? [] : $this->pieces($index, $edge, $splines);
            if ([] === $pieces) {
                continue;
            }
            // As `dot`, from where the line ends, short of its arrowheads.
            $this->cut(
                $edge,
                $pieces,
                \max(0.1, \min(10.0, DotNodes::number($edge->attribute('arrowsize'), 1.0))),
                self::penWidth($edge->attribute('penwidth'), self::styles($edge->attribute('style'))),
            );
            $points = self::joined($pieces);
            foreach (['taillabel' => $points[0], 'headlabel' => $points[\count($points) - 1]] as $attribute => $end) {
                $label = DotNodes::endText($this->graph, $edge, $attribute);
                if ($label->empty()) {
                    continue;
                }
                $labels[] = [$end, $label->size()];
                $owners[] = [$index, $attribute];
            }
        }
        $places = [];
        foreach (DotEndLabels::placed($labels, $obstacles, $this->placement->directions[''] ?? Direction::TopBottom) as $at => [$left, $top, $right, $bottom]) {
            [$index, $attribute] = $owners[$at];
            $places[$index][$attribute] = [($left + $right) / 2, ($top + $bottom) / 2];
        }

        return $places;
    }

    /**
     * An edge's head and tail labels by its ends. Where `labelangle` or `labeldistance` is set,
     * as DOT documents: 10 points times the distance from the end, turned by the angle, in
     * degrees counter-clockwise, from the edge's direction there. Otherwise where `endPlaces()`
     * set them, as dot sets them by default.
     *
     * @param list<array{float, float}> $points the edge's path, tail to head, clipped at its ends
     */
    private function endLabels(int $index, DotEdge $edge, array $points): void
    {
        $explicit = '' !== $edge->attribute('labelangle') || '' !== $edge->attribute('labeldistance');
        $angle = \deg2rad(DotNodes::number($edge->attribute('labelangle'), -25.0));
        $distance = 10 * \max(0.0, \min(100.0, DotNodes::number($edge->attribute('labeldistance'), 1.0)));
        foreach (['headlabel' => \array_reverse($points), 'taillabel' => $points] as $attribute => $path) {
            $text = DotNodes::endText($this->graph, $edge, $attribute);
            if ($text->empty()) {
                continue;
            }
            if (!$explicit) {
                $this->beside($text, ...$this->ends[$index][$attribute]);
                continue;
            }
            [$x, $y] = $path[0];
            [$intoX, $intoY] = self::direction($path);
            $this->beside($text, $x + ($distance * (($intoX * \cos($angle)) + ($intoY * \sin($angle)))), $y + ($distance * (($intoY * \cos($angle)) - ($intoX * \sin($angle)))));
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
        $boxes = [...\array_map(DotEdgePaths::box(...), \array_values($this->placement->graph->nodes)), ...$this->taken];
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

    /**
     * An edge's parts, one after the other.
     *
     * @param list<array{list<array{float, float}>, bool, bool}> $pieces
     * @return list<array{float, float}>
     */
    private static function joined(array $pieces): array
    {
        $points = [];
        foreach ($pieces as [$piece]) {
            \array_push($points, ...$piece);
        }

        return $points;
    }

    /**
     * How a fill list blends: out from the centre for `radial`, else across.
     *
     * @param array<string, true> $styles
     */
    private static function shading(array $styles): Shading
    {
        return \array_key_exists('radial', $styles) ? Shading::Radial : Shading::Linear;
    }

    /** @param array<string, true> $styles */
    private static function penWidth(string $value, array $styles): float
    {
        $width = \is_numeric($value) ? \max(0.0, \min(50.0, (float) $value)) : 1.0;

        return \array_key_exists('bold', $styles) ? \max($width, 2.0) : $width;
    }

    /** Whether the drawing is turned to landscape, as `rotate=90`, `landscape` or an `orientation` starting with `l` ask; dot turns it no other way. */
    private function landscape(): bool
    {
        return (
            '90' === \trim($this->graph->attribute('rotate'))
            || \in_array(\strtolower($this->graph->attribute('landscape')), ['true', 'yes', '1'], strict: true)
            || \str_starts_with(\strtolower($this->graph->attribute('orientation')), 'l')
        );
    }

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
