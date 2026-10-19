<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;

/**
 * What DOT attributes make of nodes, edges and clusters to draw them: their label text, the
 * size a node's label needs inside its shape and margin, and its outline.
 */
final class DotNodes
{
    public static function text(DotGraph $graph, DotNode $node): DotText
    {
        return DotText::parse(
            $node->attribute('label', '\N'),
            ['N' => $node->name, 'G' => $graph->name, 'L' => ''],
            self::fontSize($node->attribute('fontsize')),
            $node->attribute('fontname'),
            DotColors::resolve($node->attribute('fontcolor'), '#000000'),
        );
    }

    public static function edgeText(DotGraph $graph, DotEdge $edge): DotText
    {
        $operator = $graph->directed ? '->' : '--';

        return DotText::parse(
            $edge->attribute('label'),
            ['E' => $edge->tail.$operator.$edge->head, 'T' => $edge->tail, 'H' => $edge->head, 'G' => $graph->name, 'L' => ''],
            self::fontSize($edge->attribute('fontsize')),
            $edge->attribute('fontname'),
            DotColors::resolve($edge->attribute('fontcolor'), '#000000'),
        );
    }

    public static function clusterText(DotSubgraph $cluster): DotText
    {
        return DotText::parse(
            $cluster->attribute('label'),
            ['G' => $cluster->name, 'L' => ''],
            self::fontSize($cluster->attribute('fontsize')),
            $cluster->attribute('fontname'),
            DotColors::resolve($cluster->attribute('fontcolor'), '#000000'),
        );
    }

    public static function graphText(DotGraph $graph): DotText
    {
        return DotText::parse(
            $graph->attribute('label'),
            ['G' => $graph->name, 'L' => ''],
            self::fontSize($graph->attribute('fontsize')),
            $graph->attribute('fontname'),
            DotColors::resolve($graph->attribute('fontcolor'), '#000000'),
        );
    }

    /** @return array{float, float} the size a node needs for its label, in points */
    public static function size(DotGraph $graph, DotNode $node): array
    {
        $shape = $node->attribute('shape', 'ellipse');
        [$width, $height] = self::text($graph, $node)->size();
        $margins = \array_map(\trim(...), \explode(',', $node->attribute('margin', '0.11,0.055')));
        $marginX = self::number($margins[0], 0.11) * DotLayoutInput::POINTS;
        $marginY = self::number($margins[1] ?? $margins[0], 0.055) * DotLayoutInput::POINTS;
        $growth = \in_array($shape, DotShape::BARE, strict: true) ? 1.0 : DotShape::growth($shape);
        $width = \min(DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS, ($width + (2 * $marginX)) * $growth);
        $height = \min(DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS, ($height + (2 * $marginY)) * $growth);
        if (DotShape::round($shape) || 'true' === \strtolower($node->attribute('regular'))) {
            $width = \max($width, $height);
            $height = $width;
        }

        return [$width, $height];
    }

    public static function shape(DotNode $node, float $width, float $height): DotShape
    {
        $number = static fn (string $name, float $default): float => self::number($node->attribute($name), $default);

        return DotShape::of(
            $node->attribute('shape', 'ellipse'),
            $width,
            $height,
            (int) $number('peripheries', default: 1),
            (int) $number('sides', default: 4),
            $number('skew', default: 0.0),
            $number('distortion', default: 0.0),
            $number('orientation', default: 0.0),
        );
    }

    /** A numeric attribute's value, or a default when it is not a number. */
    public static function number(string $value, float $default): float
    {
        return \is_numeric($value) ? (float) $value : $default;
    }

    private static function fontSize(string $value): float
    {
        return \max(1.0, \min(500.0, self::number($value, 14.0)));
    }
}
