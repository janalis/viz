<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Gantt\GanttGroup;
use Janalis\Viz\PlantUML\Gantt\GanttSeparator;
use Janalis\Viz\PlantUML\Gantt\GanttTask;
use Janalis\Viz\PlantUML\Gantt\GanttTime;
use Janalis\Viz\PlantUML\Gantt\PlantUmlGantt;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GanttReaderTest extends TestCase
{
    public function testATaskTakesItsDaysOnTheDaysOpen(): void
    {
        $chart = self::read(
            "Project starts 2021-01-04\nsaturday are closed\nsunday are closed\n2021-01-11 is closed\n[Design] requires 7 days\nthen [Build] requires 5 days\n[Review] happens at [Build]'s end",
        )->chart;
        [$design, $build, $review] = \array_values($chart->entries);

        $this->assertInstanceOf(GanttTask::class, $design);
        $this->assertInstanceOf(GanttTask::class, $build);
        $this->assertInstanceOf(GanttTask::class, $review);
        $this->assertSame(['2021-01-04', '2021-01-14'], [self::date($design->start()), self::date($design->end())]);
        $this->assertSame(['2021-01-14', '2021-01-21'], [self::date($build->start()), self::date($build->end())]);
        $this->assertSame([true, '2021-01-20'], [$review->diamond, self::date($review->start())]);
        $this->assertCount(1, $chart->constraints);
    }

    public function testAResourceAtHalfItsTimeTakesTwiceAsLongAndNotOnItsDaysOff(): void
    {
        $task = self::read("Project starts 2022-03-07\nsaturday are closed\nsunday are closed\n[Task2] on {Bob:50%} requires 3 days\n{Bob} is off on 2022-03-09")->chart->task('Task2');

        $this->assertNotNull($task);
        $this->assertSame(['2022-03-07', '2022-03-16'], [self::date($task->start()), self::date($task->end())]);
        $this->assertSame('Task2 {Bob:50%}', $task->prettyDisplay());
    }

    public function testSentencesChainWithAndAndDurationsAddUp(): void
    {
        $chart = self::read("[A] LASTS 1 week and 2 days and is 40% complete\n[B] requires 2 days\n[B] starts 3 days after [A]'s end and is colored in Red/Blue");
        $a = $chart->chart->task('A');
        $b = $chart->chart->task('B');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame([9 * GanttTime::DAY, 40], [$a->end() - $a->start(), $a->completion]);
        $this->assertSame([12 * GanttTime::DAY, [['#ff0000', '#0000ff']]], [$b->start(), $b->colors]);
    }

    public function testGroupsAndSeparatorsAreRowsInOrder(): void
    {
        $entries = \array_values(self::read("-- One --\ngroup [G]\n[A] lasts 2 days\nend group\n--")->chart->entries);

        $this->assertSame([GanttSeparator::class, GanttGroup::class, GanttTask::class, GanttSeparator::class], \array_map(static fn (object $entry): string => $entry::class, $entries));
        $this->assertSame(
            [2 * GanttTime::DAY],
            \array_values(\array_map(static fn ($group): int => $group->end(), \array_filter($entries, static fn (object $entry): bool => $entry instanceof GanttGroup))),
        );
    }

    public function testTheTableNumbersDaysWithoutAFirstDay(): void
    {
        $drawing = Work::run(self::read("[Prototype design] requires 15 days\n[Test prototype] requires 10 days\n[Test prototype] starts at [Prototype design]'s end")->drawing());
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));

        foreach (['Start', 'Day 1', 'Day 15', '15 days', 'Day 16', 'Day 25', '10 days'] as $cell) {
            $this->assertContains($cell, $texts);
        }
    }

    public function testTheModelJoinsTasksByTheirArrows(): void
    {
        $graph = self::read("[A] as [a] lasts 2 days\n[B] lasts 1 day\n[a] -> [B]")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['a', 'A'], ['B', 'B']], \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes));
        $this->assertSame([['a', 'B']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'a start after the tasks' => ["[A] lasts 2 days\nProject starts 2020-01-01", 'Starting point must be set before task definition'];
        yield 'a date without a first day' => ['[A] starts 2020-01-01', 'No starting date for the project'];
        yield 'no such task' => ["[A] lasts 2 days\n[B] starts at [C]'s end", 'No such task C'];
        yield 'a line nothing reads' => ['hello world', 'Syntax Error?'];
        yield 'over 20 years' => ['[A] lasts 9000 days', 'Gantt diagrams cannot last more than 20 years'];
        yield 'no group to end' => ['end group', 'No group to be closed'];
        yield 'working hours' => ["from 9:00 to 17:00 are working hours\n[A] lasts 1 day", 'working hours'];
        yield 'another language' => ['language fr', 'English only'];
        yield 'a completion over 100' => ['task default completion to 120', 'Completetion must between 0 and 100'];
        yield 'a command not drawn' => ["Project starts 2020-01-01\nprint between 2020-01-01 and 2020-02-01", 'does not draw: print between'];
        yield 'an arrow from no task' => ["[A] lasts 1 day\nX -> A", 'No such task X'];
        yield 'an arrow of a thickness' => ["[A] lasts 1 day\n[B] lasts 1 day\n[A] -[thickness=3]-> [B]", 'styled thickness=3'];
        yield 'no such task to colour' => ['[X] #red', 'No such task X'];
        yield 'no such colour' => ["[A] lasts 1 day\n[A] #notacolor", 'No such color notacolor'];
        yield 'no such border colour' => ["[A] lasts 1 day\n[A] #red/notacolor", 'No such color notacolor'];
        yield 'an empty note' => ["[A] lasts 1 day\nnote bottom\nend note", 'No note defined'];
        yield 'it before any task' => ['it lasts 2 days', 'Not sure what are you refering to?'];
        yield 'he before any resource' => ['he is off on tuesday', 'Not sure who are you refering to?'];
        yield 'no verb after and' => ['[A] lasts 2 days and flies', 'No matching verb phrase'];
        yield 'a resource at no share' => ['[A] on {Bob:0%} lasts 1 day', 'Bad argument for resource'];
        yield 'a resource on no task' => ["Project starts 2020-01-06\n{Bob} works on [Z]", 'No such task [Z]'];
        yield 'a resource off for ever' => ["Project starts 2020-01-06\n[A] on {Bob} lasts 3 days\n{Bob} is off after 2020-01-07", 'Infinite loop'];
        yield 'a resource off before it all' => ["Project starts 2020-01-06\n[A] on {Bob} lasts 3 days\n{Bob} is off before 2020-01-20\n[A] ends 2020-01-10", 'Infinite loop'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startgantt\n".$lines."\n@endgantt"));
    }

    private static function read(string $lines): PlantUmlGantt
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startgantt\n".$lines."\n@endgantt");
        self::assertInstanceOf(PlantUmlGantt::class, $diagram);

        return $diagram;
    }

    private static function date(int $seconds): string
    {
        return \gmdate('Y-m-d', $seconds);
    }
}
