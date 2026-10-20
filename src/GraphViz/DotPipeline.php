<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\GraphViz\Drawing\DotDrawing;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\DotPorts;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotNode;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Layout\Dot\DotEngine;

/**
 * DOT drawn natively: parsed as the language reads it, checked against what the native
 * drawing leaves to `dot`, laid out with dot's method, and drawn.
 */
final readonly class DotPipeline implements Pipeline
{
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
        $place = new DotEngine()->place($input);
        yield from $place;

        return DotDrawing::draw($graph, $input, $place->getReturn());
    }
}
