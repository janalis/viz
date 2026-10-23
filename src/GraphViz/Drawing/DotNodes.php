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
        return self::label($node, '\\N', ['N' => $node->name, 'G' => $graph->name, 'L' => ''], images: $graph->images);
    }

    public static function edgeText(DotGraph $graph, DotEdge $edge): DotLabel
    {
        $operator = $graph->directed ? '->' : '--';

        return self::label($edge, '', ['E' => $edge->tail.$operator.$edge->head, 'T' => $edge->tail, 'H' => $edge->head, 'G' => $graph->name, 'L' => ''], images: $graph->images);
    }

    public static function clusterText(DotSubgraph $cluster): DotLabel
    {
        return self::label($cluster, '', ['G' => $cluster->name, 'L' => '']);
    }

    public static function graphText(DotGraph $graph): DotLabel
    {
        return self::label($graph, '', ['G' => $graph->name, 'L' => ''], images: $graph->images);
    }

    /** An edge's `headlabel` or `taillabel`, in its `labelfont*` font, or its own where none is set: HTML too. */
    public static function endText(DotGraph $graph, DotEdge $edge, string $attribute): DotLabel
    {
        $operator = $graph->directed ? '->' : '--';

        return self::label(
            $edge,
            '',
            ['E' => $edge->tail.$operator.$edge->head, 'T' => $edge->tail, 'H' => $edge->head, 'G' => $graph->name, 'L' => ''],
            $attribute,
            [
                $edge->attribute('labelfontsize', $edge->attribute('fontsize')),
                $edge->attribute('labelfontname', $edge->attribute('fontname')),
                $edge->attribute('labelfontcolor', $edge->attribute('fontcolor')),
            ],
            $graph->images,
        );
    }

    /** A node's or edge's `xlabel`, outside it, in its font: HTML too. */
    public static function externalText(DotGraph $graph, DotNode|DotEdge $object): DotLabel
    {
        $names = $object instanceof DotNode
            ? ['N' => $object->name, 'G' => $graph->name, 'L' => '']
            : ['T' => $object->tail, 'H' => $object->head, 'G' => $graph->name, 'L' => ''];

        return self::label($object, '', $names, 'xlabel', images: $graph->images);
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
        // As `dot` is seen to wrap a label: 8 either side and 4 above and below unless a margin is written, in inches.
        $margins = \array_map(\trim(...), \explode(',', $node->attribute('margin')));
        $marginX = self::number($margins[0], 8 / DotLayoutInput::POINTS) * DotLayoutInput::POINTS;
        $marginY = self::number($margins[1] ?? $margins[0], 4 / DotLayoutInput::POINTS) * DotLayoutInput::POINTS;
        [$across, $growth] = \in_array($shape, DotShape::BARE, strict: true) ? [1.0, 1.0] : [DotShape::across($shape), DotShape::growth($shape)];
        // An image needs its own size and a point either side, as dot measures it, its label laid over it.
        $image = $graph->images[$node->attribute('image')] ?? null;
        [$imageWidth, $imageHeight] = null === $image ? [0.0, 0.0] : [$image->width + 2, $image->height + 2];
        // As `dot` is seen to size a node with no label to show and no image: no room for it, nor its margin.
        if (0.0 === $width && 0.0 === $height && null === $image) {
            return [0.0, 0.0];
        }
        [$width, $height] = [\max($width + (2 * $marginX), $imageWidth), \max($height + (2 * $marginY), $imageHeight)];
        $least = DotLayoutInput::least($node)[1];
        // As `dot` is seen to fit a shape grown round its label to the height it keeps at least, where its label a half again as tall fits it: that tall, or as its growth past an ellipse's makes it, no wider than holds the label within it.
        [$width, $height] = match (true) {
            'star' === $shape => self::star($width, $height),
            $growth > 1 && ($height * \M_SQRT2) < $least => [(($width * $across) / \M_SQRT2) / \sqrt(1 - ($height / $least) ** 2), \max($least, ($height * $growth) / \M_SQRT2)],
            default => [$width * $across, $height * $growth],
        };
        $width = \min(DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS, $width);
        $height = \min(DotLayoutInput::MAX_INCHES * DotLayoutInput::POINTS, $height);
        if (DotShape::round($shape) || 'true' === \strtolower($node->attribute('regular'))) {
            $width = \max($width, $height);
            $height = $width;
        }

        return [$width, $height];
    }

    /**
     * The size a node takes with its outlines around it, as `dot` is seen to size it.
     *
     * @return array{float, float}
     */
    public static function ringed(DotNode $node, float $width, float $height): array
    {
        return DotShape::grown($node->attribute('shape', 'ellipse'), $width, $height, self::peripheries($node));
    }

    /** How many outlines a node is drawn with, where written. */
    private static function peripheries(DotNode $node): ?int
    {
        $written = \trim($node->attribute('peripheries'));

        return \is_numeric($written) ? \max(0, (int) $written) : null;
    }

    public static function shape(DotNode $node, float $width, float $height): DotShape
    {
        $number = static fn (string $name, float $default): float => self::number($node->attribute($name), $default);
        $shape = $node->attribute('shape', 'ellipse');
        $peripheries = self::peripheries($node);
        // As `dot` sizes a node with its outlines around it: the outline within drawn that much smaller.
        [$width, $height] = DotShape::inner($shape, $width, $height, $peripheries);

        return DotShape::of(
            $shape,
            $width,
            $height,
            $peripheries,
            (int) $number('sides', default: 4),
            $number('skew', default: 0.0),
            $number('distortion', default: 0.0),
            $number('orientation', default: 0.0),
        );
    }

    /**
     * A star round its label, as `dot` is seen to size it: as wide as 2.618 times the label's
     * width or 4.454 times its height, and a regular star's height for that width.
     *
     * @return array{float, float}
     */
    private static function star(float $width, float $height): array
    {
        $across = \max($width * 2.618_034, $height * 4.454_12);

        return [$across, $across * \cos(\M_PI / 10)];
    }

    /** @return array{float, float} the size of a record that shows its node's name */
    private static function named(DotNode $node): array
    {
        [$width, $height] = DotText::parse($node->name, [], self::fontSize($node->attribute('fontsize')))->size();

        return [$width + 16.0, $height + 8.0];
    }

    /**
     * An object's label in its font: HTML when it was written as an HTML string, else text, its escapes standing for names.
     *
     * @param array<string, string> $names
     * @param ?array{string, string, string} $font its size, face and colour, where not the object's own
     * @param array<string, \Janalis\Viz\GraphViz\DotImage> $images the images an HTML label's `<IMG>` may name, read already
     */
    private static function label(DotNode|DotEdge|DotSubgraph|DotGraph $object, string $default, array $names, string $attribute = 'label', ?array $font = null, array $images = []): DotLabel
    {
        [$size, $name, $color] = $font ?? [$object->attribute('fontsize'), $object->attribute('fontname'), $object->attribute('fontcolor')];
        $size = self::fontSize($size);
        $color = DotColors::resolve($color, '#000000');
        if (!\array_key_exists($attribute, $object->html)) {
            return DotText::parse($object->attribute($attribute, $default), $names, $size, $name, $color);
        }
        $face = DotText::face($name);

        return HtmlParser::parse(
            $object->attribute($attribute),
            new HtmlFont(
                $size,
                $color,
                \in_array($face, [FontFace::Bold, FontFace::BoldItalic], strict: true),
                \in_array($face, [FontFace::Italic, FontFace::BoldItalic], strict: true),
            ),
            $images,
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
