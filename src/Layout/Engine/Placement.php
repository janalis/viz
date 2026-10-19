<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Engine;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;

/** Where an engine put everything: nodes and frames, every endpoint by id, each scope's direction, each edge's waypoints. */
final readonly class Placement
{
    /**
     * @param array<array-key, GraphNode>                     $endpoints  every node and cluster by id
     * @param array<array-key, Direction>                     $directions each cluster's direction, the top level's under ''
     * @param array<int, list<array{float, float}>>           $routes     waypoints by edge index, for edges that span ranks
     */
    public function __construct(
        public GraphLayout $graph,
        public array $endpoints,
        public array $directions = [],
        public array $routes = [],
    ) {}
}
