<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Neato;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Free\FreePlacement;
use Janalis\Viz\Layout\Free\Stress;
use Janalis\Viz\Source\Unsupported;

/**
 * neato's layout, as Gansner, Koren and North describe it in "Graph Drawing by Stress
 * Majorization" (2004): nodes placed so that their distances match the lengths of the
 * shortest paths between them, each pair weighing the inverse square of its path, by
 * majorization until the stress settles. Each connected part is laid out on its own and the
 * parts stand side by side. Edges run straight between centres; clusters are not drawn.
 */
final readonly class NeatoEngine implements LayoutEngine
{
    /** The most nodes a part may hold: each pass costs the square of its size. */
    public const int MAX_NODES = 600;

    /**
     * @return \Generator<int, null, mixed, Placement>
     * @throws Unsupported when a connected part holds more than {@see self::MAX_NODES} nodes
     */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $count = \count($graph->nodes);
        $index = [];
        foreach ($graph->nodes as $position => $node) {
            $index[$node->id] = $position;
        }
        $lengths = \array_fill(0, $count, []);
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$index[$edge->from] ?? null, $index[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $length = $edge->length > 0 ? $edge->length : 72.0;
            $lengths[$tail][$head] = \min($lengths[$tail][$head] ?? \INF, $length);
            $lengths[$head][$tail] = $lengths[$tail][$head];
        }
        $placed = [];
        foreach (Stress::parts($lengths) as $part) {
            if (\count($part) > self::MAX_NODES) {
                throw new Unsupported('The graph is too large to lay out natively with neato: a connected part holds '.\count($part).' nodes');
            }
            $work = Stress::majorize($part, $lengths);
            yield from $work;
            $placed[] = $work->getReturn();
        }

        return FreePlacement::of($graph, FreePlacement::sideBySide($graph, $placed));
    }
}
