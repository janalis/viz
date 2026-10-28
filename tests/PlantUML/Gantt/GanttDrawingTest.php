<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Gantt;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Gantt\GanttColors;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GanttDrawingTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function blends(): iterable
    {
        // As the jar draws them, 50 or 70% complete.
        yield 'a light colour towards a dark one' => ['#e6e6fa', '#008000', 50, '#c0cdf4'];
        yield 'red towards blue' => ['#ff0000', '#0000ff', 50, '#ff8000'];
        yield 'green towards grey' => ['#00ff00', '#808080', 50, '#48ef10'];
        yield 'blue towards yellow' => ['#0000ff', '#ffff00', 70, '#00fff8'];
        yield 'pink towards cyan' => ['#ffc0cb', '#00ffff', 70, '#ec7eff'];
        yield 'grey towards magenta' => ['#808080', '#ff00ff', 70, '#6dab54'];
        yield 'teal towards plum' => ['#10e0a0', '#a01080', 70, '#1062ca'];
    }

    #[DataProvider('blends')]
    public function testACompletionTurnsAColourTowardsAnotherAsPlantUmlDoes(string $from, string $to, int $completion, string $expected): void
    {
        $this->assertSame($expected, GanttColors::blend($from, $to, $completion));
    }

    public function testNoCompletionKeepsTheFirstColourAndAWholeOneTakesTheSecond(): void
    {
        $this->assertSame(['#ff0000', '#0000ff', null, 'none'], [
            GanttColors::blend('#ff0000', '#0000ff', 0),
            GanttColors::blend('#ff0000', '#0000ff', 100),
            GanttColors::blend(null, '#0000ff', 50),
            GanttColors::blend('none', '#0000ff', 50),
        ]);
    }

    public function testATaskColouredForCompletionIsFilledWithTheBlend(): void
    {
        $drawing = self::draw("Project starts 2021-01-04\n[A] requires 4 days\n[A] is colored for completion from Lavender/Red to Green/Blue\n[A] is 50% completed");

        // As the jar fills and strokes it.
        $this->assertContains('#c0cdf4', \array_map(static fn (Mark $mark): string => $mark->fill, $drawing->marks));
        $this->assertContains('#ff8000', \array_map(static fn (Mark $mark): string => $mark->stroke, $drawing->marks));
        $this->assertSame([205.0, 96.0], self::extent($drawing));
    }

    /** @return iterable<string, array{string, array{float, float}}> */
    public static function charts(): iterable
    {
        // Each as the jar sizes it.
        yield 'arrows from and to a milestone, each side' => [
            "Project starts 2021-01-04\n[A] requires 4 days\n[M] happens at [A]'s end\n[B] starts at [M]'s end\n[B] requires 2 days\n[C] starts at [M]'s start\n[C] requires 2 days\n[D] ends at [M]'s end\n[D] requires 2 days",
            [237.0, 164.0],
        ];
        yield 'an arrow from a group' => ["Project starts 2021-01-04\ngroup [G]\n[A] requires 4 days\nend group\n[B] starts at [G]'s end\n[B] requires 2 days", [237.0, 132.0]];
        yield 'nested groups' => ["Project starts 2021-01-04\ngroup [G]\ngroup [H]\n[A] requires 4 days\nend group\nend group", [205.0, 134.0]];
        yield 'a separator with no comment' => ["Project starts 2021-01-04\n[A] requires 2 days\n--\n[B] requires 2 days", [173.0, 133.0]];
        yield 'an arrow up from a lower task' => ["Project starts 2021-01-04\n[A] requires 2 days\n[B] requires 2 days\n[A] starts at [B]'s end", [205.0, 113.0]];
        yield 'a paused task partly complete' => ["Project starts 2021-01-04\n[A] requires 6 days\n[A] pauses on 2021-01-06\n[A] pauses on wednesday\n[A] is 30% completed", [260.0, 96.0]];
        yield 'a milestone displayed as text' => ["Project starts 2021-01-04\n[M] happens 2021-01-08\n[M] is displayed as Hello\n[A] starts at [M]'s end\n[A] requires 2 days", [260.0, 113.0]];
        yield 'a separator on a background' => [
            "<style>\nganttDiagram {\n  separator {\n    BackGroundColor lightblue\n  }\n}\n</style>\nProject starts 2021-01-04\n[A] requires 2 days\n-- Phase --\n[B] requires 2 days",
            [173.0, 146.0],
        ];
        yield 'a long name beside an arrow out of its end' => ["Project starts 2021-01-04\n[A very long task name here] as [A] requires 1 day\n[B] requires 2 days\n[A] -> [B]", [305.0, 113.0]];
        yield 'a resource named twice' => ["Project starts 2021-01-04\n[B] on {Bob} {Bob:50} requires 2 days", [269.0, 128.0]];
        yield 'a day closed for a task' => ["Project starts 2021-01-04\n2021-01-06 is closed for [A]\n[A] requires 3 days", [205.0, 96.0]];
        yield 'days coloured then after' => ["Project starts 2021-01-04\n2021-01-05 is colored in salmon\nthen 2 days are colored in pink\n[A] requires 3 days", [205.0, 96.0]];
        yield 'a start working days after' => [
            "Project starts 2021-01-04\nsaturday are closed\nsunday are closed\n[A] requires 2 days\n[B] starts 2 working days after [A]'s end\n[B] requires 2 days",
            [276.0, 113.0],
        ];
        yield 'then before any task' => ["Project starts 2021-01-04\nthen [A] requires 2 days", [173.0, 96.0]];
        yield 'an arrow from named days, not drawn' => ["Project starts 2021-01-04\n2021-01-05 to 2021-01-06 are named [Kickoff]\n[B] starts at [Kickoff]'s end\n[B] requires 1 day", [214.0, 112.0]];
        yield 'arrows from a group\'s start' => [
            "Project starts 2021-01-04\ngroup [G]\n[A] requires 4 days\nend group\n[B] starts at [G]'s start\n[B] requires 2 days\n[C] ends at [G]'s start\n[C] requires 1 day",
            [205.0, 149.0],
        ];
        yield 'an arrow from a short task\'s start' => ["printscale daily zoom 0.3\nProject starts 2021-01-04\n[A] requires 1 day\n[B] requires 2 days\n[B] ends at [A]'s start", [160.0, 113.0]];
        yield 'a pause before the start, set twice' => ["Project starts 2021-01-04\n[A] starts 2021-01-08\n[A] starts 2021-01-05\n[A] requires 4 days\n[A] pauses on 2021-01-04", [276.0, 96.0]];
        yield 'a long name beside an arrow into its end' => [
            "Project starts 2021-01-04\n[A very long task name here] as [A] requires 1 day\n[B] requires 2 days\n[B] ends at [A]'s end",
            [315.0, 113.0],
        ];
        yield 'an end at named days, no arrow drawn' => ["Project starts 2021-01-04\n2021-01-05 to 2021-01-06 are named [Kickoff]\n[B] requires 3 days\n[B] ends at [Kickoff]'s end", [189.0, 112.0]];
        yield 'a separator across weeks' => [
            "printscale weekly\nProject starts 2021-01-04\n[A] requires 20 days\n[B] starts 2021-01-11\n[B] requires 2 days\nSeparator just at [B]'s start",
            [234.0, 77.0],
        ];
        yield 'a task of no days in the table' => ["show column duration\nProject starts 2021-01-04\n[A] requires 0 days\n[B] requires 3 days", [189.0, 113.0]];
        yield 'no task' => ['Project starts 2021-01-04', [149.0, 80.0]];
        yield 'a timeline on a background' => [
            "<style>\nganttDiagram {\n  timeline {\n    BackGroundColor lightyellow\n  }\n}\n</style>\nProject starts 2021-01-04\n[A] requires 2 days",
            [173.0, 96.0],
        ];
        yield 'a resource\'s load past a separator' => ["Project starts 2021-01-04\n[A] on {Alice} requires 2 days\n-- Next --\n[B] on {Alice} requires 2 days", [219.0, 178.0]];
        yield 'a separator in the header' => ["Project starts 2021-01-04\n[A] requires 4 days\nSeparator just at [A]'s end", [205.0, 96.0]];
        yield 'days over two months' => ["Project starts 2021-01-28\n[A] requires 10 days", [310.0, 96.0]];
        yield 'weeks by their calendar date' => ["printscale weekly with calendar date\nProject starts 2021-01-04\n[A] requires 20 days", [228.0, 60.0]];
        yield 'weeks numbered from 5' => ["printscale weekly with week numbering from 5\nProject starts 2021-01-04\n[A] requires 20 days", [228.0, 60.0]];
        yield 'a table with a milestone and a separator' => [
            "show column start\nshow column end\nshow column duration\nProject starts 2021-01-04\n[A] requires 2 days\n[M] happens at [A]'s end\n-- sep --",
            [183.0, 146.0],
        ];
    }

    /** @param array{float, float} $expected */
    #[DataProvider('charts')]
    public function testItIsAsBigAsPlantUmlDrawsIt(string $lines, array $expected): void
    {
        $this->assertSame($expected, self::extent(self::draw($lines)));
    }

    public function testATaskWorkedOutBeforeADayIsClosedKeepsItsDays(): void
    {
        $texts = static fn (string $lines): array => \array_values(\array_map(
            static fn (Mark $mark): string => $mark->text,
            \array_filter(self::draw($lines)->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && \str_starts_with($mark->text, 'Day')),
        ));

        // As the jar draws them: B worked out when T starts at its end, before the weekends close; T's own start, worked out after, past them.
        $this->assertSame(['Day 1', 'Day 9', 'Day 12', 'Day 12'], $texts("[B] lasts 9 days\n[T] starts at [B]'s end\nsaturday are closed\nsunday are closed"));
        $this->assertSame(['Day 1', 'Day 13', 'Day 14', 'Day 14'], $texts("saturday are closed\nsunday are closed\n[B] lasts 9 days\n[T] starts at [B]'s end"));
    }

    public function testTheTableWritesATimeOfDayAsPlantUmlWritesIt(): void
    {
        $drawing = self::draw("show column start\nshow column end\nProject starts 2021-01-04\n[A] starts 2021-01-04 10:30:15\n[A] requires 2 days\n[B] starts 2021-01-04 10:30\n[B] requires 1 day");
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));

        // As the jar writes them: seconds only where there are some.
        $this->assertContains('Jan 4 10:30:15', $texts);
        $this->assertContains('Jan 6 10:30:15', $texts);
        $this->assertContains('Jan 4 10:30', $texts);
    }

    public function testRoundCornersAreLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer leaves Gantt tasks with round corners to PlantUML'));
        self::draw("<style>\nganttDiagram {\n  task {\n    RoundCorner 5\n  }\n}\n</style>\nProject starts 2021-01-04\n[A] requires 2 days");
    }

    public function testANoteBeforeAnyTaskIsRefusedAsPlantUmlRefusesIt(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('No task defined');
        self::draw("Project starts 2021-01-04\nnote bottom\noops\nend note\n[A] requires 2 days");
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startgantt\n{$lines}\n@endgantt"));
    }

    /** @return array{float, float} */
    private static function extent(Drawing $drawing): array
    {
        return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
    }
}
