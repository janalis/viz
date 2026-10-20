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
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Fdp\FdpEngine;
use Janalis\Viz\Layout\Neato\NeatoEngine;
use Janalis\Viz\Layout\Nop\NopEngine;
use Janalis\Viz\Layout\Osage\OsageEngine;
use Janalis\Viz\Layout\Patchwork\PatchworkEngine;
use Janalis\Viz\Layout\Twopi\TwopiEngine;

/**
 * DOT drawn natively: parsed as the language reads it, checked against what the native
 * drawing leaves to `dot`, laid out with dot's method, and drawn.
 */
final readonly class DotPipeline implements Pipeline
{
    /** The layout the graph asks for: neato by distances along edges, twopi on rings, circo on circles, fdp cluster by cluster and sfdp likewise without them, osage in grids, patchwork as a treemap, nop where the graph says, any other left here dot. */
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
            'fdp', 'sfdp' => new FdpEngine(self::points($graph->attribute('K'), 21.6)),
            'osage' => new OsageEngine(),
            'patchwork' => new PatchworkEngine(self::areas($graph)),
            'nop', 'nop1', 'nop2' => new NopEngine(DotPositions::nodes($graph), DotPositions::edges($graph), DotPositions::labels($graph)),
            'twopi' => new TwopiEngine(self::points($gap, 72.0), '' === $root ? null : $root),
            default => new DotEngine(),
        };
    }

    /**
     * Each node's `area`, in points squared, where it gives one: patchwork's unit is a thousand.
     *
     * @return array<string, float>
     */
    private static function areas(DotGraph $graph): array
    {
        $areas = [];
        foreach ($graph->nodes as $node) {
            $area = \trim($node->attribute('area'));
            if (!\is_numeric($area) || (float) $area <= 0) {
                continue;
            }
            $areas[$node->name] = \min(1e6, (float) $area) * PatchworkEngine::UNIT;
        }

        return $areas;
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
        if ('patchwork' === \strtolower($graph->attribute('layout'))) {
            // patchwork draws each node as the box it fills, where no shape is asked for.
            foreach ($graph->nodes as $node) {
                $node->attributes['shape'] ??= 'box';
            }
        }
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
        if ('sfdp' === \strtolower($graph->attribute('layout'))) {
            // sfdp lays out as fdp but heeds no clusters, and draws none.
            $input = new LayoutGraph(
                \array_map(static fn (LayoutNode $node): LayoutNode => new LayoutNode($node->id, $node->label, $node->width, $node->height, null, $node->order), $input->nodes),
                $input->edges,
                spacing: $input->spacing,
            );
        }
        $place = self::engine($graph)->place($input);
        yield from $place;

        return DotDrawing::draw($graph, $input, $place->getReturn());
    }
}
