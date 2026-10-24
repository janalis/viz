<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Flow;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Flow\FlowTile;
use Janalis\Viz\PlantUML\Flow\PlantUmlFlow;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowReaderTest extends TestCase
{
    public function testEachBoxStandsTwoCellsFromTheLastTowardsItsSide(): void
    {
        $flow = self::read("a \"A\"\nb \"B\"\ne c \"C\"\nn d \"D\"");

        $this->assertSame([['a', 0, 0], ['b', 0, 2], ['c', 2, 2], ['d', 2, 0]], \array_map(static fn (FlowTile $tile): array => [$tile->id, $tile->xmin, $tile->ymin], $flow->tiles));
        $this->assertSame(['s', 'e', 'n'], \array_map(static fn (array $path): string => $path[1], $flow->paths));
    }

    public function testABoxWhereOneStandsMovesThemEast(): void
    {
        $flow = self::read("a \"A\"\ne b \"B\"\nw c \"C\"");

        $this->assertSame([['a', 2], ['b', 4], ['c', 0]], \array_map(static fn (FlowTile $tile): array => [$tile->id, $tile->xmin], $flow->tiles));
    }

    public function testOnlyWhatStandsAsFarEastAndDownMovesEast(): void
    {
        $flow = self::read("a \"A\"\nb \"B\"\ne c \"C\"\nw d \"D\"");

        // d takes b's cell: b and c, as far east and down, move on; a, above, stays.
        $this->assertSame([['a', 0, 0], ['b', 2, 2], ['c', 4, 2], ['d', 0, 2]], \array_map(static fn (FlowTile $tile): array => [$tile->id, $tile->xmin, $tile->ymin], $flow->tiles));
    }

    public function testTheFieldIsAsBigAsItsCellsWithNoMargin(): void
    {
        $drawing = Work::run(self::read("a \"A\"\ne b \"B\"\nw a")->drawing());
        $dots = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#a80036' === $mark->fill);

        $this->assertCount(2, $dots);
        // Its cells, and 1 more each way, as PlantUML sizes it.
        $this->assertEqualsWithDelta([401.0, 71.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT], 0.001);
    }

    public function testTheModelJoinsBoxesByTheirPaths(): void
    {
        $graph = self::read("a \"A\"\nb \"B\"")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['a', 'A'], ['b', 'B']], \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes));
        $this->assertSame([['a', 'b']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'a link before any box' => ['a', 'No box to link from'];
        yield 'a link to no box' => ["a \"A\"\nb", 'No box b'];
        yield 'boxes that do not face each other' => ["a \"A\"\nb \"B\"\ne c \"C\"\nn a", 'do not face each other'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startflow\n".$lines."\n@endflow"));
    }

    private static function read(string $lines): PlantUmlFlow
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startflow\n".$lines."\n@endflow");
        self::assertInstanceOf(PlantUmlFlow::class, $diagram);

        return $diagram;
    }
}
