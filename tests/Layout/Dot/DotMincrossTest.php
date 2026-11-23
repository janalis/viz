<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The nodes of one rank left to right after crossing minimisation, each as `dot` 16.1.0 sets them. */
final class DotMincrossTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function graphs(): iterable
    {
        // n0's two edges to n3 cross n1's edge to n2 twice, so n0 comes first; with one, the order the search meets them stands.
        yield 'a crossing counted once for each edge between the same two nodes' => ['digraph { n3; n1; n0; n2; n1 -> n2; n0 -> n3; n0 -> n3; n1 -> n3; n1 -> n2; n0 -> n2 }', ['n0', 'n1']];
        yield 'one edge each way, the order the search meets them' => ['digraph { n3; n1; n0; n2; n1 -> n2; n0 -> n3; n1 -> n3; n0 -> n2 }', ['n1', 'n0']];
        // The initial order is transposed before the flat edge puts n0 before n2.
        yield 'the initial order transposed before its flat edges order it' => ['digraph { n2; n4; n0; n1; n5; n3; n0 -> n1; n2 -> n3; n2 -> n5; n0 -> n2 [minlen=0] }', ['n1', 'n3', 'n5']];
        yield 'a cluster ordered from its initial order by the long pass alone' => [
            'digraph { n1; n0; n3; n2; n4; n0 -> n1; n1 -> n3; n2 -> n3; n2 -> n4; n1 -> n2; subgraph cluster_a { subgraph cluster_b { n3; n4; n0 } n1; n2 } }',
            ['n3', 'n4', 'n0'],
        ];
        // Below cluster a, n1's edges to n2 and n3 and n0's to n3 all end in cluster c, which counts as one node until ordered from inside: nothing crosses there, and the order the search meets them stands.
        yield 'a cluster counted as one node until ordered from inside' => [
            'digraph { nodesep=0.25; newrank=true; node [shape=rect, fixedsize=true, width=0.3, height=0.3]; n1 [width=1.0]; n3; n0 [width=1.0]; n2 [height=0.5]; n1 -> n2; n1 -> n3; n0 -> n3; subgraph cluster_a { subgraph cluster_c { n3; n2; n1 } subgraph cluster_d { n0 } } }',
            ['n2', 'n3'],
        ];
        // n3 ahead of n0 would have n0's edge to n1 cross the run of cluster a from n3 to n2, a thousand crossings.
        yield 'an edge across a cluster not yet ordered from inside costing a thousand' => [
            'digraph { nodesep=0.486111; newrank=true; node [shape=rect, fixedsize=true, width=0.5, height=0.5]; n1; n0; n3 [width=1.0]; n2 [width=0.3]; n0 -> n2; n0 -> n1; n3 -> n1; subgraph cluster_a { n2; n3 } }',
            ['n0', 'n3'],
        ];
        // Cluster b's run from n0 down to n1 counts in n1's median, which puts it before n2 inside cluster a.
        yield 'the run of a cluster not yet ordered from inside counted in its median' => [
            'digraph { nodesep=0.25; newrank=true; node [shape=rect, fixedsize=true, width=1.0, height=0.3]; n0; n1; n2 [width=0.5, height=0.5]; {rank=same; n2; n1} n2 -> n1; n0 -> n2 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="43" HEIGHT="17"><TR><TD></TD></TR></TABLE>>]; subgraph cluster_a { subgraph cluster_b { n1; n0 } subgraph cluster_d { } n2 } }',
            ['n1', 'n2'],
        ];
        // The flat edges from n1 to n3 leave cluster a, which joins n3 to no component: n2 is met before cluster a's.
        yield 'a flat edge out of a cluster joining no component' => [
            'digraph { nodesep=0.486111; node [shape=rect, fixedsize=true, height=0.5]; n3 [width=0.75]; n2 [width=1.0]; n0 [width=0.75, height=0.3]; n1 [width=0.5, height=0.3]; n0 -> n1 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="20" HEIGHT="17"><TR><TD></TD></TR></TABLE>>]; n1 -> n3 [minlen=0]; n1 -> n3 [minlen=0]; subgraph cluster_a { n0; n1 } }',
            ['n2', 'n0'],
        ];
        // After the clusters, the whole graph is ordered once more, where the flat edge from n0 to n2 inside cluster b keeps no order.
        yield 'the whole graph ordered once more, a flat edge inside a cluster keeping no order' => [
            'digraph { nodesep=0.486111; newrank=true; node [shape=rect, fixedsize=true, width=0.5, height=0.3]; n1; n0 [height=0.5]; n2 [width=1.0]; n1 -> n2; {rank=same; n0; n2} n0 -> n2; n0 -> n1 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="43" HEIGHT="15"><TR><TD></TD></TR></TABLE>>]; n2 -> n0; subgraph cluster_a { subgraph cluster_b { n0; n2 } subgraph cluster_d { n1 } } }',
            ['n2', 'n0'],
        ];
        // At the top, cluster a starts from n0 and goes on first along its own run to n1, met before the label node of n0's edge to n2.
        yield 'a cluster not yet ordered from inside following its own run first' => [
            'digraph { nodesep=0.486111; newrank=true; node [shape=rect, fixedsize=true, height=0.5]; n0 [width=1.0, height=0.3]; n1 [width=0.5]; n2 [width=0.3]; n0 -> n2 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="20" HEIGHT="17"><TR><TD></TD></TR></TABLE>>]; n1 -> n2 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="20" HEIGHT="15"><TR><TD></TD></TR></TABLE>>, minlen=0]; subgraph cluster_a { n1; n0 } }',
            ['n1', 'n2'],
        ];
        // Cluster a's edges, all its nodes' together, are met in the order the graph meets them, not node by node in the order its nodes stand.
        yield 'the edges of a cluster met in the order the graph meets them' => [
            'digraph { nodesep=0.75; newrank=true; node [shape=rect, fixedsize=true, width=0.75, height=0.5]; n3 [height=0.3]; n1; n2 [width=0.5]; n0; n2 -> n0 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="43" HEIGHT="15"><TR><TD></TD></TR></TABLE>>]; n0 -> n3; {rank=same; n2; n3} n2 -> n3; subgraph cluster_a { subgraph cluster_c { n3; n1 } subgraph cluster_d { n2 } } }',
            ['n2', 'n3', 'n1'],
        ];
        // n1 and n4 trade places over n2, though the flat edge from n2 to n4 then points back.
        yield 'a flat edge keeping a node jumped over before the other holding nothing' => [
            'digraph { n5 -> n1; n0 -> n5; {rank=same; n0; n5} {rank=same; n2; n4} n2 -> n4; n0 -> n4 }',
            ['n4', 'n2', 'n1'],
        ];
        // n0's edge to n2, in cluster c inside its own cluster a, is met before its edges to n1.
        yield 'an edge into a cluster inside its tail cluster met first' => [
            'digraph { nodesep=0.75; node [shape=rect, fixedsize=true, width=1.0, height=0.3]; n2; n1 [width=0.75]; n0; n0 -> n2 [minlen=0]; n0 -> n1; n0 -> n1; n0 -> n1; n0 -> n2 [label=<<TABLE FIXEDSIZE="TRUE" WIDTH="10" HEIGHT="15"><TR><TD></TD></TR></TABLE>>]; subgraph cluster_a { subgraph cluster_c { n2 } subgraph cluster_d { } n0; n1 } }',
            ['n2', 'n1'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('graphs')]
    public function testARankIsOrderedAsDotOrdersIt(string $source, array $expected): void
    {
        $this->assertSame($expected, self::leftToRight($source, $expected));
    }

    /**
     * @param list<string> $names
     * @return list<string> the nodes so named, left to right
     */
    private static function leftToRight(string $source, array $names): array
    {
        $x = [];
        foreach (Work::run(new DotPipeline()->drawing($source))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind || !\in_array($mark->text, $names, strict: true)) {
                continue;
            }
            $x[$mark->text] = $mark->points[0][0];
        }
        \asort($x);

        return \array_map(strval(...), \array_keys($x));
    }
}
