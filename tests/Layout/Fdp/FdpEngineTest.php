<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Fdp;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Fdp\FdpEngine;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class FdpEngineTest extends TestCase
{
    public function testClustersHoldTheirNodesAndNoBoxesOverlap(): void
    {
        $placement = self::place(
            'graph { subgraph cluster_x { a -- b -- c -- a; subgraph cluster_z { z1 -- z2 } } subgraph cluster_y { d -- e } c -- d; e -- f; f -- g -- h -- f; a -- h; z2 -- g; lone }',
        );
        $boxes = [];
        foreach ($placement->graph->clusters as $cluster) {
            $boxes[$cluster->name] = $cluster;
        }

        foreach (['cluster_x' => ['a', 'b', 'c', 'z1', 'z2'], 'cluster_z' => ['z1', 'z2'], 'cluster_y' => ['d', 'e']] as $name => $members) {
            foreach ($members as $member) {
                $this->assertTrue(self::holds($boxes[$name], $placement, $member), $name.' holds '.$member);
            }
        }
        $this->assertTrue($boxes['cluster_x']->x0 < $boxes['cluster_z']->x0 && $boxes['cluster_z']->x1 < $boxes['cluster_x']->x1, 'z within x');
        foreach (['f', 'g', 'h', 'lone'] as $outside) {
            $this->assertFalse(self::holds($boxes['cluster_x'], $placement, $outside) || self::holds($boxes['cluster_y'], $placement, $outside), $outside.' outside');
        }
        $nodes = \array_values($placement->graph->nodes);
        foreach ($nodes as $one => $node) {
            foreach (\array_slice($nodes, $one + 1) as $other) {
                $this->assertTrue(\abs($node->x - $other->x) >= (($node->w + $other->w) / 2) || \abs($node->y - $other->y) >= (($node->h + $other->h) / 2), $node->name.' clear of '.$other->name);
            }
        }
        $this->assertGreaterThanOrEqual($boxes['cluster_x']->x1 + 4, $placement->graph->width, 'the drawing holds its clusters');
    }

    public function testAnEdgeIsAsLongAsItsEndsAndKBetweenThem(): void
    {
        $placement = self::place('graph { a -- b }', new FdpEngine(spring: 10.0));
        [$a, $b] = [$placement->endpoints['a'], $placement->endpoints['b']];

        $this->assertEqualsWithDelta(27 + 27 + 10, \hypot($a->x - $b->x, $a->y - $b->y), 1.0);
    }

    public function testAnEmptyClusterIsALabelsBoxAndTooManyItemsGoToFdp(): void
    {
        $placement = Work::run(new FdpEngine()->place(new LayoutGraph([new LayoutNode('a', 'a', 54, 36)], [], [new LayoutCluster('x', 'X', minWidth: 30.0, labelHeight: 10.0)])));
        $this->assertSame(30.0 + 16, $placement->graph->clusters[0]->x1 - $placement->graph->clusters[0]->x0);

        $this->expectException(Unsupported::class);
        Work::run(new FdpEngine()->place(new LayoutGraph(\array_map(static fn (int $index): LayoutNode => new LayoutNode('n'.$index, '', 1, 1), \range(0, FdpEngine::MAX_ITEMS)), [])));
    }

    public function testTheDotPipelineDrawsWithFdpAndItsClusters(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing('graph { layout=fdp; K=1; subgraph cluster_x { label=X; a -- b } b -- c }'));

        $this->assertCount(4, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind), 'a cluster box and three nodes');
    }

    private static function holds(GraphCluster $box, Placement $placement, string $name): bool
    {
        $node = $placement->endpoints[$name];

        return ($node->x - ($node->w / 2)) >= $box->x0 && ($node->x + ($node->w / 2)) <= $box->x1 && ($node->y - ($node->h / 2)) >= $box->y0 && ($node->y + ($node->h / 2)) <= $box->y1;
    }

    private static function place(string $source, FdpEngine $engine = new FdpEngine()): Placement
    {
        return Work::run($engine->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)))));
    }
}
