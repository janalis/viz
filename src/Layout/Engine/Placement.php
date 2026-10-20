<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/** Where an engine put everything: nodes and frames, every endpoint by id, each scope's direction, each edge's waypoints and curve. */
final readonly class Placement
{
    /**
     * @param array<array-key, GraphNode>                     $endpoints  every node and cluster by id
     * @param array<array-key, Direction>                     $directions each cluster's direction, the top level's under ''
     * @param array<int, list<array{float, float}>>           $routes     waypoints by edge index, for edges that span ranks
     * @param array<int, array{float, float}>                 $labels     where each edge's label centres, by edge index, where the engine placed it
     * @param array<int, list<array{float, float}>>           $splines    each edge's curve, drawn in straight steps from its tail's centre to its head's, by edge index, where the engine routed one
     * @param array<int, list<array{float, float}>>           $polylines  each routed edge's shortest path, corner to corner, the curve's skeleton
     */
    public function __construct(
        public GraphLayout $graph,
        public array $endpoints,
        public array $directions = [],
        public array $routes = [],
        public array $labels = [],
        public array $splines = [],
        public array $polylines = [],
    ) {}
}
