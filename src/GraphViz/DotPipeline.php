<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\GraphViz\Drawing\DotDrawing;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\DotPorts;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Circo\CircoEngine;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Neato\NeatoEngine;
use Janalis\Viz\Layout\Twopi\TwopiEngine;

/**
 * DOT drawn natively: parsed as the language reads it, checked against what the native
 * drawing leaves to `dot`, laid out with dot's method, and drawn.
 */
final readonly class DotPipeline implements Pipeline
{
    /** The layout the graph asks for: neato by distances along edges, twopi on rings, circo on circles, any other left here dot. */
    private static function engine(DotGraph $graph): LayoutEngine
    {
        $root = $graph->attribute('root');
        foreach ($graph->nodes as $node) {
            $root = '' === $root && \in_array(\strtolower($node->attribute('root')), ['true', 'yes', '1'], strict: true) ? $node->name : $root;
        }
        $gap = \explode(':', $graph->attribute('ranksep'))[0];

        return match (\strtolower($graph->attribute('layout'))) {
            'neato' => new NeatoEngine(),
            'circo' => new CircoEngine(self::points($graph->attribute('mindist'), 72.0)),
            'twopi' => new TwopiEngine(self::points($gap, 72.0), '' === $root ? null : $root),
            default => new DotEngine(),
        };
    }

    /** A length in inches, in points: a default where it is no number, bounded otherwise. */
    private static function points(string $inches, float $default): float
    {
        $inches = \trim($inches);

        return \is_numeric($inches) ? \max(0.02, \min(DotLayoutInput::MAX_INCHES, (float) $inches)) * DotLayoutInput::POINTS : $default;
    }

    #[\Override]
    public function drawing(string $source): \Generator
    {
        $parse = new DotParser()->parse($source);
        yield from $parse;
        $graph = $parse->getReturn();
        DotFeatureGate::check($graph);
        $input = DotLayoutInput::from(
            $graph,
            static fn (DotNode $node): array => DotNodes::size($graph, $node),
            static fn (DotEdge $edge): array => DotNodes::edgeText($graph, $edge)->size(),
            static fn (DotSubgraph $cluster): array => DotNodes::clusterText($cluster)->size(),
            static fn (DotNode $node, string $port, float $width, float $height): ?array => DotPorts::anchor($graph, $node, DotNodes::record($graph, $node), $port, [
                -$width / 2,
                -$height / 2,
                $width / 2,
                $height / 2,
            ])[0],
        );
        $place = self::engine($graph)->place($input);
        yield from $place;

        return DotDrawing::draw($graph, $input, $place->getReturn());
    }
}
