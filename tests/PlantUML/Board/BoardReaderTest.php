<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Board;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Board\BoardCard;
use Janalis\Viz\PlantUML\Board\PlantUmlBoard;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class BoardReaderTest extends TestCase
{
    public function testReleasesStandUnderTheLastCardOfAStageAbove(): void
    {
        $board = self::read("A\n+B\n++C\n+++D\n++E\n+F\nG\n+H");

        $this->assertSame(['A', 'G'], \array_map(static fn (BoardCard $card): string => $card->text, $board->activities));
        [$first] = $board->activities;
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], \array_map(static fn (BoardCard $card): string => $card->text, $first->all()));
        $this->assertSame(2, $first->place(0));
        $this->assertSame([0, 0, 0, 0, 1, 2], \array_map(static fn (BoardCard $card): int => $card->column, $first->all()));
    }

    public function testCardsStandOnAGridWithDashedLinesBetweenStages(): void
    {
        $drawing = Work::run(self::read("A\n+B\n+C\nD")->drawing());
        $texts = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $texts[] = [$mark->text, \round($mark->points[0][1] / Units::POINT)];
        }
        $lines = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind && $mark->dashed));

        // The activity's card twice, as PlantUML draws it; its releases a stage down, 90 apart.
        $this->assertSame(['A', 'A', 'B', 'C', 'D', 'D'], \array_column($texts, 0));
        $this->assertEqualsWithDelta(90.0, $texts[2][1] - $texts[0][1], 0.5);
        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(3 * 170, ($lines[0]->points[1][0] - $lines[0]->points[0][0]) / Units::POINT, 0.001);
    }

    public function testTheModelJoinsEachCardToTheOneAboveIt(): void
    {
        $graph = self::read("A\n+B\n++C")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['A', 'B', 'C'], \array_map(static fn (Node $node): ?string => $node->label, $graph->nodes));
        $this->assertSame([['n0', 'n1'], ['n1', 'n2']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    public function testAReleaseBeforeAnyActivityIsRefused(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('A release before any activity');

        self::read('+A');
    }

    private static function read(string $lines): PlantUmlBoard
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startboard\n".$lines."\n@endboard");
        self::assertInstanceOf(PlantUmlBoard::class, $diagram);

        return $diagram;
    }
}
