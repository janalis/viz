<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Gantt\GanttChart;
use Janalis\Viz\PlantUML\Gantt\GanttTask;
use Janalis\Viz\PlantUML\Gantt\GanttTime;
use Janalis\Viz\PlantUML\Gantt\PlantUmlGantt;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class GanttSentencesTest extends TestCase
{
    private const string START = "Project starts 2020-01-06\n";

    public function testATaskEndsWhenWrittenItsStartFoundBackFromItsEnd(): void
    {
        // Its end and its load set, its start is worked out back, past the days closed.
        $plain = self::task(self::START."[A] ends 2020-01-10\n[A] lasts 2 days", 'A');
        $weekend = self::task(self::START."saturday are closed\nsunday are closed\n[A] ends 2020-01-14\n[A] lasts 3 days", 'A');
        $nothing = self::task(self::START."[A] ends 2020-01-10\n[A] lasts 0 days", 'A');

        $this->assertSame(['2020-01-09', '2020-01-11'], self::span($plain));
        $this->assertSame(['2020-01-10', '2020-01-15'], self::span($weekend));
        $this->assertSame(['2020-01-11', '2020-01-11'], self::span($nothing));
        $this->assertSame(0, self::task('[A] lasts 0 days', 'A')->end());
    }

    public function testATaskStartsAndEndsAtAnInstantOfAnother(): void
    {
        $chart = self::read(self::START."[A] lasts 4 days\n[B] lasts 2 days\n[B] ends at [A]'s end\n[C] lasts 1 day\n[C] starts 2 days before [A]'s end\n[D] starts 2020-01-06 10:30\n[D] lasts 1 day");

        $this->assertSame(['2020-01-08', '2020-01-10'], self::span(self::of($chart, 'B')));
        $this->assertSame(['2020-01-08', '2020-01-09'], self::span(self::of($chart, 'C')));
        $this->assertSame('10:30', \gmdate('H:i', self::of($chart, 'D')->start()));
        $this->assertSame(['2020-01-08 12:00', '2020-01-10 12:00'], \array_map(static fn (int $time): string => \gmdate('Y-m-d H:i', $time), [...(static fn (GanttTask $task): array => [
            $task->start(),
            $task->end(),
        ])(self::task(self::START."[A] lasts 2 days\n[A] ends 2020-01-10 12:00", 'A'))]));
        $this->assertSame(['2020-01-08', '2020-01-09'], self::span(self::task("Project starts 6 January 2020\n[A] starts January 8 2020\n[A] lasts 1 day", 'A')));
        $this->assertCount(2, $chart->chart->constraints);
    }

    public function testWorkingDaysAfterAnInstantSkipTheDaysClosed(): void
    {
        $task = self::task(self::START."saturday are closed\nsunday are closed\n[A] lasts 5 days\n[B] starts 1 working day after [A]'s end\n[B] lasts 1 day", 'B');

        // As the jar sets it: the Sunday after A, the day it ends on, not counted.
        $this->assertSame(['2020-01-13', '2020-01-14'], self::span($task));
    }

    public function testATaskPausesOnDaysOrWeekdays(): void
    {
        $days = static fn (string $lines): array => \array_map(static fn (int $day): string => \gmdate('m-d', GanttTime::start($day)), \array_keys(self::task(self::START.$lines, 'A')->pausedDays));

        $this->assertSame(['01-07', '01-08'], $days("[A] lasts 6 days\n[A] pauses from 2020-01-07 to 2020-01-08"));
        $this->assertSame(['01-07', '01-08'], $days("[A] lasts 6 days\n[A] pauses from D+1 to D+2"));
        $this->assertSame(['01-07', '01-08', '01-09', '01-10', '01-11', '01-12', '01-13', '01-14'], $days("[B] lasts 9 days\n[A] lasts 6 days\n[A] pauses from 2020-01-07 to [B]'s end"));
        $this->assertSame([1 => true], self::task(self::START."[A] lasts 6 days\n[A] pauses on monday", 'A')->pausedWeekdays);
    }

    public function testATaskTakesItsLinksColoursStereotypeAndDurations(): void
    {
        $task = self::task(self::START."[A] <<risky>> lasts 2 days\n[A] links to [[http://example.com]]\n[A] is colored for completion from red to blue", 'A');

        $this->assertSame([['#ff0000', null], ['#0000ff', null]], $task->colors);
        $this->assertSame(['risky'], $task->stereotypes);
        $this->assertSame(5420, self::task('[A] lasts 1 hour and 30 minutes and 20 seconds', 'A')->end());
        $this->assertSame([7 * GanttTime::DAY, 30 * GanttTime::DAY], [self::task('[A] lasts 1 week', 'A')->end(), self::task('[A] lasts 1 month', 'A')->end()]);
    }

    public function testATaskStartsAtDaysNamed(): void
    {
        $chart = self::read(self::START."[A] lasts 3 days\n2020-01-08 to 2020-01-09 are named [Mid]\n[B] lasts 1 day\n[B] starts at [Mid]'s start");

        // Days named stand for a moment, which constrains nothing.
        $this->assertSame(['2020-01-08', '2020-01-09'], self::span(self::of($chart, 'B')));
        $this->assertSame([], $chart->chart->constraints);
        $this->assertSame(['2020-01-10', '2020-01-11'], self::span(self::task(self::START."2020-01-08 to 2020-01-09 are named [Mid]\n[B] lasts 1 day\n[B] starts at [Mid]'s end", 'B')));
    }

    public function testResourcesAreOffAndOnAsWritten(): void
    {
        $closed = static function (string $lines): array {
            $calendar = self::read(self::START."[A] on {Bob} lasts 3 days\n".$lines)->chart->resources['Bob']->calendar;

            return \array_values(\array_map(static fn (int $day): string => \gmdate('m-d', GanttTime::start($day)), \array_filter(\range(18_267, end: 18_283), $calendar->isClosed(...))));
        };

        $this->assertSame(['01-06'], $closed('{Bob} is off before 2020-01-07'));
        $this->assertSame(['01-07', '01-08'], $closed('{Bob} is off from 2020-01-07 to 2020-01-08'));
        $this->assertSame(['01-14', '01-21'], $closed("{Bob} is off on tuesday\nhe is on 2020-01-07"));
        $this->assertSame(['01-21'], $closed("{Bob} is off on tuesday\n{Bob} is on from 2020-01-07 to 2020-01-14"));
        $this->assertSame(['2020-01-07', '2020-01-10'], self::span(self::task(self::START."[A] on {Bob} lasts 3 days\n{Bob} is off before 2020-01-07", 'A')));
    }

    public function testDaysAreClosedOpenedColouredAndNamedTogether(): void
    {
        $then = self::read(self::START."2020-01-06 and 3 days are closed\n[A] lasts 2 days\nthen 2 days are colored in red");
        $opened = self::read(self::START."saturday are closed\n2020-01-11 to 2020-01-12 are opened\nsaturday are open for [A]\n[A] lasts 7 days");

        $this->assertSame(['2020-01-10', '2020-01-12'], self::span(self::of($then, 'A')));
        $this->assertSame(['2020-01-06', '2020-01-13'], self::span(self::of($opened, 'A')));
        $this->assertSame(['2020-01-06', '2020-01-13'], self::span(self::task(self::START."D+2 to D+4 are closed for [A]\n2020-01-08 to 2020-01-10 are opened for [A]\n[A] lasts 7 days", 'A')));
    }

    public function testADayIsWrittenFromTheFirstTheLastOrToday(): void
    {
        $chart = self::read(self::START."today is 2020-01-07\nT+1 is colored in red\n[A] lasts 3 days\nE-1 is colored in blue\nD+1 is closed")->chart;

        $colors = $chart->colors;
        \ksort($colors);
        $this->assertSame(
            ['01-07' => '#0000ff', '01-08' => '#ff0000'],
            \array_combine(\array_map(static fn (int $day): string => \gmdate('m-d', GanttTime::start($day)), \array_keys($colors)), $colors),
        );
        $this->assertFalse($chart->isOpen(18_268));
    }

    public function testCommandsSetTheChartsWeeksScalesAndColumns(): void
    {
        $chart = self::read(
            "labels on first column\nweeks starts on Sunday and must have at least 1 day\nhide resources names\nhide resources footbox\ntask default completion to 30\n[A] lasts 2 days\n[B] lasts 1 day\nA -[#red,dashed]-> B\n[A] #red/blue\nlanguage en\nprintscale weekly with calendar date zoom 2",
        )->chart;

        $this->assertSame([7, 1, true, true, 30], [$chart->firstWeekday, $chart->minimalDaysInFirstWeek, $chart->hideResourceNames, $chart->hideResourceFootbox, $chart->defaultCompletion]);
        $this->assertSame([['#ff0000', '#0000ff']], self::of(self::read('[A] lasts 1 day'."\n".'[A] #red/blue'), 'A')->colors);
        $this->assertSame([['#ff0000', null]], self::of(self::read("[A] lasts 1 day\n[A] #red"), 'A')->colors);
        $this->assertSame(['#ff0000', 'dashed'], [$chart->constraints[0]->color, $chart->constraints[0]->style]);
        $this->assertSame(['weekly', 2.0, -1], [$chart->scale, $chart->zoom, $chart->weekNumbering]);
        $this->assertSame(['yearly', 'daily', 3], [
            self::read('printscale yearly')->chart->scale,
            self::read('printscale daily')->chart->scale,
            self::read('printscale weekly with week numbering from 3')->chart->weekNumbering,
        ]);
    }

    public function testAResourcesLoadIsWrittenEachWeekOnAWeeklyScale(): void
    {
        $texts = static fn (string $lines): array => \array_values(\array_map(
            static fn (Mark $mark): string => $mark->text,
            \array_filter(Work::run(self::read($lines)->drawing())->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind),
        ));

        // As the jar writes it: each week's load once, from 1970 each seventh day, from a first day each Sunday.
        $this->assertSame(['Start', 'End', 'Duration', 'Day 1', 'Day 10', '10 days', '1', '2', 'A {Bob}', 'Bob', '100', '1', '2'], $texts("printscale weekly\n[A] on {Bob} lasts 10 days"));
        $this->assertSame(
            ['Start', 'End', 'Duration', 'Jan 6', 'Jan 25', '20 days', '2', '3', '4', 'Jan 2020', 'A {Bob:50%}', 'Bob', '50', '50', 'Jan 2020'],
            $texts("printscale weekly\n".self::START.'[A] on {Bob:50%} lasts 10 days'),
        );
    }

    public function testTheModelLeavesSeparatorsOut(): void
    {
        $graph = self::read("[A] lasts 1 day\n-- Phase --\n[B] lasts 1 day")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['A', 'B'], \array_map(static fn (Node $node): string => $node->id, $graph->nodes));
    }

    private static function read(string $lines): PlantUmlGantt
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startgantt\n".$lines."\n@endgantt");
        self::assertInstanceOf(PlantUmlGantt::class, $diagram);

        return $diagram;
    }

    private static function task(string $lines, string $id): GanttTask
    {
        return self::of(self::read($lines), $id);
    }

    private static function of(PlantUmlGantt|GanttChart $chart, string $id): GanttTask
    {
        $task = ($chart instanceof PlantUmlGantt ? $chart->chart : $chart)->task($id);
        self::assertNotNull($task);

        return $task;
    }

    /** @return array{string, string} */
    private static function span(GanttTask $task): array
    {
        return [\gmdate('Y-m-d', $task->start()), \gmdate('Y-m-d', $task->end())];
    }
}
