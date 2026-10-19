<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\Graph;

use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    public function testLooseNodesAreThoseNoClusterHoldsAtAnyDepth(): void
    {
        $graph = new Graph(
            [new Node('a'), new Node('b'), new Node('c'), new Node('d')],
            clusters: [new Cluster('x', nodes: ['a'], clusters: [new Cluster('y', nodes: ['c'])])],
        );

        $this->assertSame(['b', 'd'], \array_map(static fn (Node $node): string => $node->id, $graph->looseNodes()));
    }

    public function testANodeIsFoundByIdentifierAndShowsItsIdentifierWithoutALabel(): void
    {
        $graph = new Graph([new Node('a', 'Alpha'), new Node('b')]);

        $this->assertSame('Alpha', $graph->node('a')?->text());
        $this->assertSame('b', $graph->node('b')?->text());
        $this->assertNull($graph->node('c'));
    }
}
