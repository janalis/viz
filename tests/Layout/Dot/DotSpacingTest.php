<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How far apart nodes stand across their rank, each as `dot` sets them, its 4-point pad added. */
final class DotSpacingTest extends TestCase
{
    private const string NODES = 'nodesep=0.486111; node [shape=box, fixedsize=true, width=0.5, height=0.3, label=""];';

    /** @return iterable<string, array{string, array<string, float>}> */
    public static function graphs(): iterable
    {
        yield 'a gap to the nearest point' => ['digraph { nodesep=0.5; node [shape=box, fixedsize=true, label=""]; a [width=1.009722]; b [width=1]; a -> c; b -> c }', ['a' => 40.35, 'b' => 148.35]];
        yield 'half a point up, on a separation set to the point' => [
            'digraph { nodesep=0.486111; node [shape=box, fixedsize=true, label=""]; a [width=1.013889]; b; a -> c; b -> c }',
            ['a' => 40.5, 'b' => 139.5],
        ];
        yield 'beside a chain, half a separation rounded down' => [
            'digraph { nodesep=0.486111; node [shape=box, fixedsize=true, label=""]; a [width=0.02]; b [width=0.02]; t [width=2]; a -> t [minlen=2]; b -> t [minlen=2] }',
            ['a' => 41.0, 'b' => 112.0],
        ];
        yield 'free of the graph\'s bounds, midway over its children' => [
            'digraph { nodesep=0.486111; node [shape=box, label="", height=0.5]; p [width=1.2]; q [width=1.2]; r [width=0.9]; s [width=1.2];'
                .' subgraph cluster_0 { a [width=1.4]; b [width=1.3]; c [width=0.9]; d [width=1.2] } a -> b; b -> c; c -> d; b -> p; q -> r; q -> s; b -> q }',
            ['p' => 179.0, 'q' => 300.0, 'r' => 244.0, 's' => 355.0, 'a' => 70.0, 'b' => 71.0, 'c' => 68.0, 'd' => 68.0],
        ];
        yield 'a chain node beside a label, on a rank between nodes\' ranks: 5 apart' => [
            'digraph { '.self::NODES.' b [width=0.02]; d [width=0.02]; a -> b [label='.self::label(60).', weight=100]; a -> d [minlen=2] }',
            ['a' => 22.0, 'b' => 22.0, 'd' => 105.0],
        ];
        yield 'two chain nodes on a rank between nodes\' ranks: 5 apart' => [
            'digraph { '.self::NODES.' d1 [width=0.02]; d2 [width=0.02]; a -> d1; a -> d2; x -> y [label='.self::label(10).'] }',
            ['d1' => 5.0, 'd2' => 46.0],
        ];
        yield 'a label node, a separation on the side away from its label' => [
            'digraph { '.self::NODES.' t [width=0.02]; b [width=0.02]; x -> y [label='.self::label(10).']; a -> b [label='.self::label(30).', minlen=2, weight=100]; t -> b }',
            ['t' => 4.72, 'b' => 75.72, 'a' => 75.72],
        ];
    }

    /** @param array<string, float> $expected */
    #[DataProvider('graphs')]
    public function testNodesStandAsDotSetsThem(string $source, array $expected): void
    {
        $this->assertEqualsWithDelta($expected, self::across($source, $expected, 'x'), 0.01);
    }

    public function testALabelNodeHoldsItsRoomInsideItsCluster(): void
    {
        // As dot sets them: the label's side 8 inside the cluster, the other half a separation and the label's width, a separation at most.
        foreach ([16 => [41.0, 26.0], 40 => [43.0, 48.0]] as $width => $expected) {
            $graph = Work::run(new DotParser()->parse('digraph { '.self::NODES.' subgraph cluster_x { a -> b [label='.self::label($width).'] } }'));
            $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph, labels: static fn (DotEdge $edge): array => DotNodes::edgeText($graph, $edge)->size())));
            [$cluster, $a] = [$placement->graph->clusters[0], $placement->graph->nodes[0]];

            $this->assertEqualsWithDelta($expected, [$a->x - $cluster->x0, $cluster->x1 - $a->x], 0.01, (string) $width);
        }
    }

    public function testANodeFreeBetweenTwoPullsIsCentredBetweenTheirEnds(): void
    {
        // As dot sets it: c pulled equally by b, straight above it, and by the label node of a's long edge, midway between them.
        $at = self::across('digraph { '.self::NODES.' a->b; b->c; a->c [label='.self::label(37).'] }', ['b' => 0.0, 'c' => 0.0], 'x');

        $this->assertEqualsWithDelta(44.0, $at['c'] - $at['b'], 0.01);
    }

    public function testRanksStandTheirSeparationApartToTheNearestPoint(): void
    {
        // As dot sets them: 0.35 inch, 25.2, apart as 25, and 0.37, 26.64, as 27.
        foreach (['0.35' => 49.0, '0.37' => 51.0] as $separation => $step) {
            $at = self::across("digraph { ranksep={$separation}; node [shape=box, fixedsize=true, width=1, height=0.333333]; a -> b -> c }", ['a' => 0.0, 'b' => 0.0, 'c' => 0.0], 'y');
            $this->assertEqualsWithDelta([$step, $step], [$at['b'] - $at['a'], $at['c'] - $at['b']], 0.01, $separation);
        }
    }

    public function testALabelNodeLaidOutToBeTurnedHoldsItsLabelOnItsOtherSide(): void
    {
        // Left to right, t faces the label: the label and a separation apart, not two separations.
        $source =
            'digraph { rankdir=LR; '
            .self::NODES
            .' t [width=0.02, height=0.02]; b [width=0.02, height=0.02]; x -> y [label='
            .self::label(10)
            .']; a -> b [label='
            .self::label(30)
            .', minlen=2, weight=100]; t -> b }';

        $this->assertEqualsWithDelta(['t' => 65.0, 'b' => 115.0], self::across($source, ['t' => 0.0, 'b' => 0.0], 'y'), 0.01);
    }

    /**
     * @param array<string, float> $expected
     * @return array<string, float> where the expected nodes stand along an axis
     */
    private static function across(string $source, array $expected, string $axis): array
    {
        $graph = Work::run(new DotParser()->parse($source));
        $input = DotLayoutInput::from($graph, labels: static fn (DotEdge $edge): array => DotNodes::edgeText($graph, $edge)->size());
        $at = [];
        foreach (Work::run(new DotEngine()->place($input))->graph->nodes as $node) {
            if (!\array_key_exists($node->name, $expected)) {
                continue;
            }
            $at[$node->name] = 'x' === $axis ? $node->x : $node->y;
        }

        return $at;
    }

    /** A label of a width, as an HTML table of that fixed size, 14 high. */
    private static function label(int $width): string
    {
        return '<<TABLE FIXEDSIZE="TRUE" WIDTH="'.$width.'" HEIGHT="14"><TR><TD></TD></TR></TABLE>>';
    }
}
