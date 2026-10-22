<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How far apart nodes stand across their rank, each as `dot` sets them, its 4-point pad added. */
final class DotSpacingTest extends TestCase
{
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
    }

    /** @param array<string, float> $expected */
    #[DataProvider('graphs')]
    public function testNodesStandAsDotSetsThem(string $source, array $expected): void
    {
        $graph = Work::run(new DotParser()->parse($source));
        $x = [];
        foreach (Work::run(new DotEngine()->place(DotLayoutInput::from($graph)))->graph->nodes as $node) {
            if (!\array_key_exists($node->name, $expected)) {
                continue;
            }
            $x[$node->name] = $node->x;
        }

        $this->assertEqualsWithDelta($expected, $x, 0.01);
    }
}
