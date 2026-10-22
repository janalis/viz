<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Dot;

use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\PlantUML\Dot\PlantUmlDot;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlDotTest extends TestCase
{
    private const string GRAPH = "digraph G {\n  rankdir=LR\n  a -> b -> c\n  a [shape=box]\n}";

    public function testTheBlockIsDrawnAsTheNativeDotRendererDrawsIt(): void
    {
        $this->assertEquals(
            Work::run(new DotPipeline()->drawing(self::GRAPH."\n")),
            Work::run(PlantUmlPipeline::native()->drawing("@startdot\n\n".self::GRAPH."\n@enddot")),
        );
    }

    public function testTheModelIsTheGraphsOwn(): void
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startdot\n".self::GRAPH."\n@enddot");

        $this->assertInstanceOf(PlantUmlDot::class, $diagram);
        $graph = $diagram->model();
        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['a', 'b'], ['b', 'c']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    /** @return iterable<string, array{string}> */
    public static function headers(): iterable
    {
        yield 'unnamed' => ['graph {'];
        yield 'strict and quoted' => ['strict digraph "a \"b\"" {'];
        yield 'numbered' => ['digraph 12 {'];
    }

    #[DataProvider('headers')]
    public function testItOpensWithAnyGraphsHeader(string $header): void
    {
        $this->assertInstanceOf(PlantUmlDot::class, PlantUmlPipeline::native()->diagram("@startdot\n".$header."\n a\n}\n@enddot"));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'an empty block' => ['', 'empty @startdot'];
        yield 'a graph on one line' => ['digraph G { a -> b }', 'not opening with a graph'];
        yield 'no graph' => ["a -> b\n}", 'not opening with a graph'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        PlantUmlPipeline::native()->diagram("@startdot\n".$lines."\n@enddot");
    }
}
