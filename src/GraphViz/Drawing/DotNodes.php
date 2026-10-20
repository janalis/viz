<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Html\HtmlFont;
use Janalis\Viz\GraphViz\Html\HtmlParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Text\FontFace;

/**
 * What DOT attributes make of nodes, edges and clusters to draw them: their label text, the
 * size a node's label needs inside its shape and margin, and its outline.
 */
final class DotNodes
{
    public static function text(DotGraph $graph, DotNode $node): DotLabel
    {
        return self::label($node, '\\N', ['N' => $node->name, 'G' => $graph->name, 'L' => '']);
    }

    public static function edgeText(DotGraph $graph, DotEdge $edge): DotLabel
    {
        $operator = $graph->directed ? '->' : '--';

        return self::label($edge, '', ['E' => $edge->tail.$operator.$edge->head, 'T' => $edge->tail, 'H' => $edge->head, 'G' => $graph->name, 'L' => '']);
    }

    public static function clusterText(DotSubgraph $cluster): DotLabel
    {
        return self::label($cluster, '', ['G' => $cluster->name, 'L' => '']);
    }

    public static function graphText(DotGraph $graph): DotLabel
    {
        return self::label($graph, '', ['G' => $graph->name, 'L' => '']);
    }

    /** An edge's `headlabel` or `taillabel`, in its `labelfont*` font, or its own where none is set. */
    public static function endText(DotGraph $graph, DotEdge $edge, string $attribute): DotText
    {
        $operator = $graph->directed ? '->' : '--';

        return DotText::parse(
            $edge->attribute($attribute),
            ['E' => $edge->tail.$operator.$edge->head, 'T' => $edge->tail, 'H' => $edge->head, 'G' => $graph->name, 'L' => ''],
            self::fontSize($edge->attribute('labelfontsize', $edge->attribute('fontsize'))),
            $edge->attribute('labelfontname', $edge->attribute('fontname')),
            DotColors::resolve($edge->attribute('labelfontcolor', $edge->attribute('fontcolor')), '#000000'),
        );
    }

    /** A node's or edge's `xlabel`, outside it, in its font. */
    public static function externalText(DotGraph $graph, DotNode|DotEdge $object): DotText
    {
        $names = $object instanceof DotNode
            ? ['N' => $object->name, 'G' => $graph->name, 'L' => '']
            : ['T' => $object->tail, 'H' => $object->head, 'G' => $graph->name, 'L' => ''];

        return DotText::parse(
            $object->attribute('xlabel'),
            $names,
            self::fontSize($object->attribute('fontsize')),
            $object->attribute('fontname'),
            DotColors::resolve($object->attribute('fontcolor'), '#000000'),
        );
    }

    /** A record node's fields, null for any other node or a label that is not a record's. */
    public static function record(DotGraph $graph, DotNode $node): ?DotRecord
    {
        if (!self::isRecord($node) || \array_key_exists('label', $node->html)) {
            return null;
        }
        $across = !\in_array(\strtoupper($graph->attribute('rankdir')), ['LR', 'RL'], strict: true);

        return DotRecord::parse($node->attribute('label', '\\N'), $across, static fn (string $label): DotText => DotText::parse(
            $label,
            ['N' => $node->name, 'G' => $graph->name, 'L' => ''],
            self::fontSize($node->attribute('fontsize')),
            $node->attribute('fontname'),
            DotColors::resolve($node->attribute('fontcolor'), '#000000'),
        ));
    }

    public static function isRecord(DotNode $node): bool
    {
        return \in_array(\strtolower($node->attribute('shape')), ['record', 'mrecord'], strict: true);
    }

    /** @return array{float, float} the size a node needs for its label, in points */
    public static function size(DotGraph $graph, DotNode $node): array
    {
        if (self::isRecord($node) && !\array_key_exists('label', $node->html)) {
            // As dot, a record whose label is not a record's shows its name.
            [$width, $height] = self::record($graph, $node)?->size() ?? self::named($node);
            $most = DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS;

            return [\min($most, $width), \min($most, $height)];
        }
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

    /** @return array{float, float} the size of a record that shows its node's name */
    private static function named(DotNode $node): array
    {
        [$width, $height] = DotText::parse($node->name, [], self::fontSize($node->attribute('fontsize')))->size();

        return [$width + 16.0, $height + 8.0];
    }

    /**
     * An object's label in its font: HTML when its `label` was written as an HTML string, else text, its escapes standing for names.
     *
     * @param array<string, string> $names
     */
    private static function label(DotNode|DotEdge|DotSubgraph|DotGraph $object, string $default, array $names): DotLabel
    {
        $size = self::fontSize($object->attribute('fontsize'));
        $color = DotColors::resolve($object->attribute('fontcolor'), '#000000');
        if (!\array_key_exists('label', $object->html)) {
            return DotText::parse($object->attribute('label', $default), $names, $size, $object->attribute('fontname'), $color);
        }
        $face = DotText::face($object->attribute('fontname'));

        return HtmlParser::parse($object->attribute('label'), new HtmlFont(
            $size,
            $color,
            \in_array($face, [FontFace::Bold, FontFace::BoldItalic], strict: true),
            \in_array($face, [FontFace::Italic, FontFace::BoldItalic], strict: true),
        ));
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
