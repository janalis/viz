<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotRenderer;
use Janalis\Viz\GraphViz\DotWriter;
use Janalis\Viz\GraphViz\GraphParseException;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\TestCase;

final class DotReaderTest extends TestCase
{
    public function testDotsResolvedGraphMapsOntoTheSharedModel(): void
    {
        $graph = DotReader::read(self::json([
            'rankdir' => 'lr',
            'label' => 'Title',
            '_subgraph_cnt' => 3,
            'objects' => [
                ['name' => 'cluster_a', 'label' => 'A', 'subgraphs' => [1], 'nodes' => [3, 4]],
                ['name' => 'plain', 'subgraphs' => [2], 'nodes' => [4]],
                ['name' => 'cluster_b', 'label' => 'B', 'nodes' => [4]],
                ['_gvid' => 3, 'name' => 'x', 'label' => '\N', 'shape' => 'diamond', 'style' => 'rounded,filled', 'fillcolor' => 'red', 'color' => 'blue'],
                ['_gvid' => 4, 'name' => 'y', 'label' => 'first\nsecond\lthird\rfourth \N', 'style' => 'rounded', 'fillcolor' => 'unused'],
                ['_gvid' => 5, 'name' => 'z', 'shape' => 'tab'],
                ['_gvid' => 6, 'name' => 'w', 'label' => 'w', 'shape' => 'point'],
            ],
            'edges' => [
                ['tail' => 3, 'head' => 4, 'label' => 'l', 'style' => 'dashed,bold', 'arrowhead' => 'odot', 'arrowtail' => 'tee', 'dir' => 'both'],
                ['tail' => 5, 'head' => 3, 'style' => 'dotted', 'dir' => 'back', 'arrowtail' => 'none'],
                ['tail' => 5, 'head' => 6, 'style' => 'bold', 'dir' => 'none'],
                ['tail' => 6, 'head' => 5, 'arrowhead' => 'lvee', 'style' => 'tapered'],
                'not an edge',
            ],
        ]));

        $this->assertSame(Direction::LeftRight, $graph->direction);
        $this->assertSame('Title', $graph->title);
        $this->assertEquals(
            [
                new Node('x', null, Shape::Diamond, 'red', 'blue'),
                new Node('y', "first\nsecond\nthird\nfourth y", Shape::Rounded),
                new Node('z', null, Shape::Note),
                new Node('w', null, Shape::Circle),
            ],
            $graph->nodes,
        );
        $this->assertEquals(
            [
                new Edge('x', 'y', 'l', ArrowHead::Circle, ArrowHead::Cross, LineStyle::Dashed),
                new Edge('z', 'x', head: ArrowHead::None, tail: ArrowHead::None, style: LineStyle::Dotted),
                new Edge('z', 'w', head: ArrowHead::None, style: LineStyle::Bold),
                new Edge('w', 'z'),
            ],
            $graph->edges,
        );
        $this->assertCount(1, $graph->clusters);
        $this->assertSame(['cluster_a', 'A', ['x']], [$graph->clusters[0]->id, $graph->clusters[0]->label, $graph->clusters[0]->nodes]);
        $this->assertSame(['cluster_b', 'B', ['y']], [$graph->clusters[0]->clusters[0]->id, $graph->clusters[0]->clusters[0]->label, $graph->clusters[0]->clusters[0]->nodes]);
    }

    public function testAnUndirectedGraphHasNoArrowsByDefault(): void
    {
        $graph = DotReader::read(self::json(['directed' => false, 'objects' => [['name' => 'a'], ['name' => 'b']], 'edges' => [['tail' => 0, 'head' => 1]]]));

        $this->assertEquals([new Edge('a', 'b', head: ArrowHead::None)], $graph->edges);
        $this->assertSame(Direction::TopBottom, $graph->direction);
    }

    public function testATopLevelPlainSubgraphLendsItsClustersUpward(): void
    {
        $graph = DotReader::read(self::json([
            '_subgraph_cnt' => 2,
            'objects' => [['name' => 'rank', 'subgraphs' => [1], 'nodes' => [2]], ['name' => 'clusterX', 'nodes' => [2]], ['name' => 'n']],
        ]));

        $this->assertSame(['clusterX', '', ['n']], [$graph->clusters[0]->id, $graph->clusters[0]->label, $graph->clusters[0]->nodes]);
    }

    public function testAnEdgeToAMissingNodeIsRefused(): void
    {
        $this->expectExceptionObject(new GraphParseException('edge references a missing node'));

        DotReader::read(self::json(['objects' => [['name' => 'a']], 'edges' => [['tail' => 0, 'head' => 9]]]));
    }

    public function testInvalidJsonIsRefused(): void
    {
        $this->expectException(GraphParseException::class);
        $this->expectExceptionMessage('dot produced invalid JSON');

        DotReader::read('{');
    }

    public function testANonObjectDocumentIsRefused(): void
    {
        $this->expectExceptionObject(new GraphParseException('dot produced a non-object JSON document'));

        DotReader::read('42');
    }

    public function testWhatTheWriterWritesDotReadsBack(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        $sample = Sample::graph();

        $graph = DotReader::read(new DotRenderer()->json(DotWriter::write($sample)));

        $this->assertSame($sample->direction, $graph->direction);
        $this->assertSame($sample->title, $graph->title);
        foreach ($sample->nodes as $node) {
            // dot has no stadium: the writer draws it as a rounded box, and that is what comes back.
            $expected = Shape::Stadium === $node->shape ? new Node($node->id, $node->label, Shape::Rounded, $node->fill, $node->stroke) : $node;
            $this->assertEquals($expected, $graph->node($node->id), $node->id);
        }
        $this->assertSame(['api', 'missing'], $graph->clusters[0]->nodes);
        $this->assertSame(['db'], $graph->clusters[0]->clusters[0]->nodes);
        // Graphviz versions list edges in different orders: newer ones as declared, older ones by subgraph.
        $edges = static function (array $edges): array {
            $rows = \array_map(static fn (Edge $edge): string => \implode('|', [$edge->from, $edge->to, $edge->label, $edge->style->value]), $edges);
            \sort($rows);

            return $rows;
        };
        $this->assertSame($edges($sample->edges), $edges($graph->edges));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return \json_encode($data, \JSON_THROW_ON_ERROR);
    }

    public function testSiblingClustersAfterAPlainSubgraphAreAllRead(): void
    {
        $graph = DotReader::read(self::json([
            '_subgraph_cnt' => 4,
            'objects' => [
                ['name' => 'plain'],
                ['name' => 'cluster_one', 'label' => 'One', 'nodes' => [5, 4, 'junk', 4.5]],
                ['name' => 'cluster_two', 'label' => 'Two', 'nodes' => [6]],
                ['name' => 'cluster_inner', 'nodes' => [5]],
                ['name' => 'a'],
                ['name' => 'b'],
                ['name' => 'cluster_node'],
            ],
        ]));

        // The inner cluster is nobody's child here, so all three are top level; `cluster_node` is a node.
        $this->assertSame(
            [['cluster_one', ['b', 'a']], ['cluster_two', ['cluster_node']], ['cluster_inner', ['b']]],
            \array_map(static fn ($cluster): array => [$cluster->id, $cluster->nodes], $graph->clusters),
        );
        $this->assertSame(['a', 'b', 'cluster_node'], \array_map(static fn (Node $node): string => $node->id, $graph->nodes));
    }

    public function testANestedMemberIsListedOnlyByItsInnermostClusterWhateverItsPosition(): void
    {
        $graph = DotReader::read(self::json([
            '_subgraph_cnt' => 2,
            'objects' => [['name' => 'cluster_outer', 'subgraphs' => [1], 'nodes' => [3, 2]], ['name' => 'cluster_inner', 'nodes' => [3]], ['name' => 'a'], ['name' => 'b']],
        ]));

        $this->assertSame(['a'], $graph->clusters[0]->nodes);
        $this->assertSame(['b'], $graph->clusters[0]->clusters[0]->nodes);
    }

    public function testAnEdgeWithoutEndpointsNeverFallsBackToTheFirstNode(): void
    {
        foreach ([['head' => 0], ['tail' => 0]] as $edge) {
            try {
                DotReader::read(self::json(['objects' => [['name' => 'a']], 'edges' => [$edge]]));
                $this->fail('Expected a refusal');
            } catch (GraphParseException $refusal) {
                $this->assertSame('edge references a missing node', $refusal->getMessage());
            }
        }
    }

    public function testTheJsonErrorIsNamed(): void
    {
        $this->expectExceptionObject(new GraphParseException('dot produced invalid JSON: Syntax error'));

        DotReader::read('{');
    }
}
