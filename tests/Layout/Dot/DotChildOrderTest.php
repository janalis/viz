<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A node's children left to right, each as `dot` sets them. */
final class DotChildOrderTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function graphs(): iterable
    {
        yield 'as they were made, not as the edges are written' => ['digraph { c; b; a -> b; a -> c }', ['c', 'b']];
        yield 'made by their edges, in turn' => ['digraph { a -> c; a -> b }', ['c', 'b']];
        yield 'those in the parent\'s cluster first' => ['digraph { c; d; subgraph cluster_0 { a; e; b } a -> c; a -> b; a -> d; a -> e }', ['e', 'b', 'c', 'd']];
        yield 'a cluster of the parent\'s own holding none of them' => ['digraph { c; b; subgraph cluster_0 { a } a -> b; a -> c }', ['c', 'b']];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function ranks(): iterable
    {
        yield 'a cluster\'s own nodes after the clusters it holds' => ['digraph { subgraph cluster_1 { p; q; subgraph cluster_2 { a } r } }', ['a', 'p', 'q', 'r']];
        yield 'the top level\'s as written' => ['digraph { x; subgraph cluster_1 { a } }', ['x', 'a']];
        yield 'a cluster\'s point after the cluster it holds, whatever it links to' => ['digraph { subgraph cluster_1 { p; subgraph cluster_2 { a } } n; p -> n [minlen=0] }', ['a', 'p', 'n']];
    }

    /** @param list<string> $expected */
    #[DataProvider('graphs')]
    public function testChildrenStandAsDotSetsThem(string $source, array $expected): void
    {
        $this->assertSame($expected, self::leftToRight($source, $expected));
    }

    /** @param list<string> $expected */
    #[DataProvider('ranks')]
    public function testARanksNodesStartAsDotSetsThem(string $source, array $expected): void
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
