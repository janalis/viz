<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Language;

use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Language\DotSyntaxError;
use Janalis\Viz\GraphViz\Model\DotEdge;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotSubgraph;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DotParserTest extends TestCase
{
    public function testTheGraphKeepsItsKindNameAndOwnAttributes(): void
    {
        $graph = self::parse('strict graph "My graph" { label="Top"; graph [rankdir=LR, bgcolor=<<b>x</b>>] }');

        $this->assertSame(['My graph', false, true], [$graph->name, $graph->directed, $graph->strict]);
        $this->assertSame(['label' => 'Top', 'rankdir' => 'LR', 'bgcolor' => '<b>x</b>'], $graph->attributes);
        $this->assertSame(['bgcolor' => true], $graph->html);
        $this->assertSame('none', $graph->attribute('missing', 'none'));
        $this->assertSame([], self::parse('digraph {}')->nodes);
    }

    public function testADefaultHoldsForLaterObjectsAndSubgraphsThatSetNoneOfTheirOwn(): void
    {
        $graph = self::parse(<<<'DOT'
            digraph {
              a;
              node [shape=box, label=<<b>B</b>>];
              b;
              subgraph s { c; node [color=red]; d }
              node [shape=circle, label="plain"];
              subgraph s { e }
              f [shape=note];
              a [color=blue];
            }
            DOT);

        $this->assertSame([], $graph->nodes['a']->html);
        $this->assertSame(['color' => 'blue'], $graph->nodes['a']->attributes, 'defaults never reach an earlier node; its own attributes still do');
        $this->assertSame(['shape' => 'box', 'label' => '<b>B</b>'], $graph->nodes['b']->attributes);
        $this->assertSame(['label' => true], $graph->nodes['b']->html);
        $this->assertSame(['shape' => 'box', 'label' => '<b>B</b>'], $graph->nodes['c']->attributes);
        $this->assertSame(['shape' => 'box', 'label' => '<b>B</b>', 'color' => 'red'], $graph->nodes['d']->attributes);
        $this->assertSame(
            ['shape' => 'circle', 'label' => 'plain', 'color' => 'red'],
            $graph->nodes['e']->attributes,
            'a reopened subgraph keeps its own defaults and sees its parent\'s as they stand',
        );
        $this->assertSame([], $graph->nodes['e']->html, 'a plain value replaces an HTML one');
        $this->assertSame('note', $graph->nodes['f']->attribute('shape'));
        $this->assertSame(['c', 'd', 'e'], self::members($graph->subgraphs[0], $graph));
    }

    public function testANewSubgraphStartsWithItsParentsGraphAttributes(): void
    {
        $graph = self::parse(<<<'DOT'
            digraph {
              subgraph cluster_early { a }
              label="L"; graph [color=<red>];
              subgraph cluster_late { b; subgraph cluster_inner { c } }
              subgraph cluster_early { subgraph cluster_inner { d } }
            }
            DOT);
        [$early, $late] = $graph->subgraphs;

        $this->assertSame([], $early->attributes);
        $this->assertSame(['label' => 'L', 'color' => 'red'], $late->attributes);
        $this->assertSame(['color' => true], $late->html);
        $this->assertSame(['label' => 'L', 'color' => 'red'], $late->subgraphs[0]->attributes);
        $this->assertSame([], $early->subgraphs[0]->attributes, 'a subgraph name is looked up in its parent only');
        $this->assertSame([$early, $early->subgraphs[0], $late, $late->subgraphs[0]], $graph->allSubgraphs());
        $this->assertTrue($early->cluster());
        $this->assertSame('x', $early->attribute('missing', 'x'));
    }

    public function testAnEdgeChainJoinsEveryNodeOfEachOperandToTheNext(): void
    {
        $graph = self::parse('digraph { b; a -> { c b } -> d:p:n [label=x] edge [color=red]; subgraph s { e -> a } }');

        $this->assertSame(['b', 'a', 'c', 'd', 'e'], \array_keys($graph->nodes), 'nodes exist from the moment they are read');
        $this->assertSame(
            ['a>b', 'a>c', 'b>d', 'c>d', 'e>a'],
            \array_map(static fn (DotEdge $edge): string => $edge->tail.'>'.$edge->head, $graph->edges),
            'a subgraph\'s nodes come in the order the graph created them',
        );
        $this->assertSame(['label' => 'x', 'headport' => 'p:n'], $graph->edges[2]->attributes);
        $this->assertSame(['color' => 'red'], $graph->edges[4]->attributes);
        $this->assertSame('', $graph->edges[0]->attribute('tailport'));
        $this->assertSame(['a', 'e'], self::members($graph->subgraphs[1], $graph));
    }

    public function testAStrictGraphKeepsOneEdgePerPair(): void
    {
        $directed = self::parse('strict digraph { a:w -> b [color=red]; a -> b [style=dashed, color=<x>]; b -> a }');
        $undirected = self::parse('strict graph { a -- b [color=red]; b -- a [style=dashed] }');

        $this->assertCount(2, $directed->edges);
        $this->assertSame(['color' => 'x', 'tailport' => 'w', 'style' => 'dashed'], $directed->edges[0]->attributes);
        $this->assertSame(['color' => true], $directed->edges[0]->html);
        $this->assertCount(1, $undirected->edges);
        $this->assertSame(['color' => 'red', 'style' => 'dashed'], $undirected->edges[0]->attributes);
    }

    public function testANamedSubgraphCanStandForItsNodesInAnEdge(): void
    {
        $graph = self::parse('digraph { subgraph s { a b } subgraph s -> c }');

        $this->assertSame(['a>c', 'b>c'], \array_map(static fn (DotEdge $edge): string => $edge->tail.'>'.$edge->head, $graph->edges));
    }

    #[DataProvider('errors')]
    public function testMalformedDotIsLocated(string $source, string $message): void
    {
        $this->expectException(DotSyntaxError::class);
        $this->expectExceptionMessage($message);

        self::parse($source);
    }

    /** @return iterable<string, array{string, string}> */
    public static function errors(): iterable
    {
        yield 'kind' => ['strict a {}', 'Line 1, column 8: Expected graph or digraph'];
        yield 'brace' => ['digraph x a', 'Line 1, column 11: Expected {'];
        yield 'close' => ['digraph { a', 'Line 1, column 12: Expected }'];
        yield 'undirected' => ['digraph { a -- b }', 'An undirected edge -- in a digraph'];
        yield 'directed' => ['graph { a -> b }', 'A directed edge -> in an undirected graph'];
        yield 'statement' => ['digraph { = }', 'Expected a node, an edge, an attribute statement or a subgraph'];
        yield 'attributes' => ['digraph { node a }', 'Expected ['];
        yield 'value' => ['digraph { a [x=] }', 'Expected a value'];
        yield 'name' => ['digraph { a [=x] }', 'Expected an identifier'];
        yield 'equals' => ['digraph { a [x y] }', 'Expected ='];
        yield 'anonymous' => ['digraph { subgraph -> a }', 'Expected {'];
    }

    /** @return list<string> */
    private static function members(DotSubgraph $subgraph, DotGraph $graph): array
    {
        return $graph->ordered($subgraph->nodes);
    }

    private static function parse(string $source): DotGraph
    {
        return Work::run(new DotParser()->parse($source));
    }
}
