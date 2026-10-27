<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Activities;

use Janalis\Viz\PlantUML\Activities\Instruction\InstructionBreak;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionFork;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionGroup;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionIf;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionRepeat;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSimple;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSpot;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSwitch;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionTerminal;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionWhile;
use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PlantUmlActivity;
use Janalis\Viz\PlantUML\Activities\PositionedNote;
use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityReaderTest extends TestCase
{
    public function testActionsAndTerminalsTakeTheArrowsWrittenBeforeThem(): void
    {
        $root = self::read(<<<'PUML'
            start
            -> going;
            :first;
            -[#red,dashed]-> on;
            :second\nline; <<input>> <<#pink>>
            :third
             over lines; <<output>>
            -[#blue]->
            stop
            (A)
            end
            PUML)->root;

        [$start, $first, $second, $third, $stop, $spot, $end] = $root->all;
        $this->assertInstanceOf(InstructionTerminal::class, $start);
        $this->assertSame('start', $start->kind);
        $this->assertInstanceOf(InstructionSimple::class, $first);
        $this->assertEquals([['first'], new LinkRendering(display: ['going'])], [$first->label, $first->inLink()]);
        $this->assertInstanceOf(InstructionSimple::class, $second);
        $this->assertEquals([['second', 'line'], new LinkRendering('#red,dashed', ['on'])], [$second->label, $second->inLink()]);
        $this->assertSame([Stereogroup::boxStyle($second->stereogroup), Stereogroup::back($second->stereogroup)], ['input', '#pink']);
        $this->assertInstanceOf(InstructionSimple::class, $third);
        $this->assertSame([['third', ' over lines'], '<<output>>'], [$third->label, $third->stereogroup]);
        $this->assertInstanceOf(InstructionTerminal::class, $stop);
        // A stop leaves the arrow written before it to the next step, as PlantUML does.
        $this->assertEquals([new LinkRendering('#blue'), new LinkRendering('#blue')], [$stop->inLink(), $spot->inLink()]);
        $this->assertInstanceOf(InstructionSpot::class, $spot);
        $this->assertSame('A', $spot->spot);
        $this->assertInstanceOf(InstructionTerminal::class, $end);
        $this->assertSame('end', $end->kind);
    }

    public function testDecisionsHoldTheirBranchesAndTheArrowsBetweenThem(): void
    {
        $root = self::read(<<<'PUML'
            if (a?) then (yes)
              :one;
              -> out of one;
            elseif (b?) then (also)
              :two;
            else (no)
              :three;
            endif <<#gold>>
            -[#green]->
            if (c) is (d) then
            endif
            #pink:if (e) then <<#gold>>
            else
            endif
            PUML)->root;

        [$first, $second, $third] = $root->all;
        $this->assertInstanceOf(InstructionIf::class, $first);
        $this->assertCount(2, $first->thens);
        $this->assertSame([['a?'], ['yes'], ['b?'], ['also']], [
            $first->thens[0]->labelTest,
            $first->thens[0]->labelPositive->display,
            $first->thens[1]->labelTest,
            $first->thens[1]->labelPositive->display,
        ]);
        $this->assertEquals(new LinkRendering(display: ['out of one']), $first->thens[0]->special);
        $this->assertTrue($first->written);
        $this->assertNotNull($first->else);
        $this->assertSame([['no'], '<<#gold>>'], [$first->else->labelPositive->display, $first->else->specialColors]);
        $this->assertEquals(new LinkRendering('#green'), $first->outColor);
        $this->assertInstanceOf(InstructionIf::class, $second);
        $this->assertSame([['c'], ['d'], false], [$second->thens[0]->labelTest, $second->thens[0]->labelPositive->display, $second->written]);
        $this->assertInstanceOf(InstructionIf::class, $third);
        // As PlantUML, the colour written before `if` is ignored: the one in `<<…>>` after it colours the diamond.
        $this->assertSame('#gold', $third->thens[0]->color);
    }

    public function testSwitchesHoldTheirCasesAndTheArrowsBetweenThem(): void
    {
        $root = self::read(<<<'PUML'
            -> in;
            #pink:switch (a?) <<#gold>>
            case ( one )
              :x;
              -> out of one;
            case ()
              note right: beside
            case (three)
              :y;
              note left: on y
              -> out of three;
            endswitch <<#green>>
            switch ()
            case (only)
            endswitch
            PUML)->root;

        [$first, $second] = $root->all;
        $this->assertInstanceOf(InstructionSwitch::class, $first);
        $this->assertSame([['a?'], '<<#gold>>', '<<#green>>', ['in']], [$first->test, $first->stereogroup, $first->endColors, $first->inLink()->display]);
        $this->assertSame([[' one '], null, ['three']], \array_map(static fn ($case): ?array => $case->labelTest, $first->cases));
        $this->assertSame([' one '], $first->cases[0]->labelPositive->display);
        $this->assertEquals(new LinkRendering(display: ['out of one']), $first->cases[0]->special);
        $this->assertEquals(new LinkRendering(display: ['out of three']), $first->cases[2]->special);
        $this->assertSame('<<#green>>', $first->cases[2]->specialColors);
        $this->assertCount(1, $first->notes, 'as PlantUML, a note in an empty case is the switch\'s');
        $this->assertSame([['on y']], \array_map(static fn ($note): array => $note->text, $first->cases[2]->list->all[0]->notes));
        $this->assertFalse($first->containsBreak());
        $this->assertInstanceOf(InstructionSimple::class, $first->last());
        $this->assertInstanceOf(InstructionSwitch::class, $second);
        $this->assertNull($second->test);
        $this->assertNull($second->last());
        $loop = self::read("while (a)\n  switch (b)\n  case (c)\n  case (d)\n    break\n  endswitch\nendwhile")->root->all[0];
        $this->assertInstanceOf(InstructionWhile::class, $loop);
        $this->assertTrue($loop->list->all[0]->containsBreak());
    }

    public function testStepsStandInTheSwimlaneWrittenBeforeThem(): void
    {
        $activity = self::read(<<<'PUML'
            split
            end split
            |A|
            start
            |#pink|B| Second
            swimlane C as Third lane
            |B|
            while (w?)
              :x;
            endwhile
            stop
            |#gold|A|
            PUML);

        $this->assertSame(
            [['A', '#gold', ['A']], ['B', '#pink', [' Second']], ['C', null, ['Third lane']]],
            \array_map(static fn ($lane): array => [$lane->name, $lane->color, $lane->title], $activity->lanes),
            'a colour written again colours it',
        );
        [$split, $start, $while] = $activity->root->all;
        $this->assertInstanceOf(InstructionWhile::class, $while);
        $this->assertNotNull($activity->laneOf);
        $this->assertNull($activity->laneOf[$split] ?? null, 'a split before any swimlane stands in none');
        $this->assertSame(['A', 'B', 'B'], [$activity->laneOf[$start], $activity->laneOf[$while], $activity->laneOf[$while->special ?? $while]]);
    }

    public function testLoopsHoldTheirBodiesLabelsAndBackwardActions(): void
    {
        $root = self::read(<<<'PUML'
            while (more?) is (yes)
              -> first arrow;
              :work;
            -[#red]-> again;
            endwhile (no)
            -[#blue]->
            stop
            repeat :begin; <<#pink>>
              :do;
              backward:back;
              note right : beside back
            repeat while (again?) is (yes) not (no) -> looping
            while (empty?)
            endwhile
            end
            repeat
              :x;
              break
            repeat while
            while (back?)
              :y;
              backward:undo; <<#pink>>
            -[#red]-> ignored;
            endwhile
            PUML)->root;

        [$while, $repeat, $empty, $last, $backward] = $root->all;
        $this->assertInstanceOf(InstructionWhile::class, $while);
        $this->assertSame([['more?'], ['first arrow']], [$while->test, $while->yes]);
        $this->assertEquals([new LinkRendering('#blue', ['no']), new LinkRendering('#red', ['again'])], [$while->outColor, $while->incoming1]);
        $this->assertInstanceOf(InstructionTerminal::class, $while->special, 'the stop is drawn on the way out');
        $this->assertInstanceOf(InstructionRepeat::class, $repeat);
        $this->assertSame([['begin'], ['again?'], ['yes'], ['no'], ['back']], [$repeat->startLabel, $repeat->test, $repeat->yes, $repeat->out, $repeat->backward]);
        $this->assertEquals(new LinkRendering(display: ['looping']), $repeat->incoming1);
        $this->assertEquals([new PositionedNote(['beside back'], 'right')], $repeat->backwardNotes);
        $this->assertInstanceOf(InstructionWhile::class, $empty);
        $this->assertSame('end', $empty->special?->kind);
        $this->assertInstanceOf(InstructionRepeat::class, $last);
        $this->assertTrue($last->containsBreak());
        $this->assertInstanceOf(InstructionBreak::class, $last->list->all[1]);
        $this->assertInstanceOf(InstructionWhile::class, $backward);
        // A backward action takes the way back: the arrow written before `endwhile` is not drawn.
        $this->assertSame([['undo'], '<<#pink>>'], [$backward->backward, $backward->backwardStereogroup]);
        $this->assertEquals([new LinkRendering(), new LinkRendering()], [$backward->incoming1, $backward->incoming2]);
    }

    public function testForksSplitsAndGroupsHoldTheirFlows(): void
    {
        $root = self::read(<<<'PUML'
            fork <<#gold>>
              :a;
              -> leaving a;
            fork again
              :b;
            end merge {joined}
            note right : on the fork
            split
              :c;
            split again
              :d;
              note left : beside d
            end split
            partition "Part" #lightblue {
              note : first
              :e;
            }
            group Other <<stereo>> {
              :f;
            }
            PUML)->root;

        [$fork, $split, $partition, $group] = $root->all;
        $this->assertInstanceOf(InstructionFork::class, $fork);
        $this->assertSame(['merge', '{joined}', 2], [$fork->style, $fork->label, \count($fork->forks)]);
        $this->assertEquals(new LinkRendering(display: ['leaving a']), $fork->forks[0]->outRendering);
        $this->assertEquals([new PositionedNote(['on the fork'], 'right')], $fork->notes);
        $this->assertInstanceOf(InstructionFork::class, $split);
        $this->assertSame('split', $split->style);
        $this->assertInstanceOf(InstructionSimple::class, $split->forks[1]->all[0]);
        $this->assertEquals([new PositionedNote(['beside d'])], $split->forks[1]->all[0]->notes);
        $this->assertInstanceOf(InstructionGroup::class, $partition);
        $this->assertSame([['Part'], 'partition', '#lightblue'], [$partition->title, $partition->type, $partition->color]);
        $this->assertEquals(new PositionedNote(['first']), $partition->note);
        $this->assertInstanceOf(InstructionGroup::class, $group);
        $this->assertSame(['group', '<<stereo>>'], [$group->type, $group->stereotype]);
    }

    public function testNotesAndKillsStandWherePlantUmlPutsThem(): void
    {
        $root = self::read(<<<'PUML'
            note right : alone
            :a;
            note left #pink
              several
              lines
            end note
            floating note right : floats
            detach
            if (x) then
              :b;
              kill
            else
              :c;
            endif
            kill
            PUML)->root;

        $this->assertEquals([new PositionedNote(['alone'], 'right')], $root->notes);
        $first = $root->all[0];
        $this->assertInstanceOf(InstructionSimple::class, $first);
        $this->assertEquals([new PositionedNote(['several', 'lines'], color: '#pink'), new PositionedNote(['floats'], 'right', floating: true)], $first->notes);
        $this->assertTrue($first->killed);
        $if = $root->all[1];
        $this->assertInstanceOf(InstructionIf::class, $if);
        $this->assertInstanceOf(InstructionSimple::class, $if->thens[0]->last());
        $this->assertTrue($if->thens[0]->last()->killed);
        $this->assertNotNull($if->else);
        $this->assertInstanceOf(InstructionSimple::class, $if->else->last());
        $this->assertTrue($if->else->last()->killed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'kill nothing' => ['kill', 'kill cannot be used here'];
        yield 'else alone' => ['else', 'Cannot find if'];
        yield 'else twice' => ["if (a) then\nelse\nelse\nendif", 'Cannot find if'];
        yield 'elseif after else' => ["if (a) then\nelse\nelseif (b) then\nendif", 'You cannot put an elseIf here'];
        yield 'endif alone' => ['endif', 'Cannot find if'];
        yield 'endwhile alone' => ['endwhile', 'Cannot find while'];
        yield 'repeat while alone' => ['repeat while (a)', 'Cannot find repeat'];
        yield 'backward alone' => [":a;\nbackward:a;", 'Cannot find repeat'];
        yield 'fork again alone' => ['fork again', 'Cannot find fork'];
        yield 'split again in a fork' => ["fork\nsplit again", 'Cannot find split'];
        yield 'closing nothing' => [":a;\n}", 'Cannot find group'];
        yield 'kill a start' => ["start\nkill", 'kill cannot be used here'];
        yield 'kill a decision ended' => ["if (a) then\n  start\nendif\nkill", 'kill cannot be used here'];
        yield 'kill a decision ended in its else' => ["if (a) then\nelse\n  start\nendif\nkill", 'kill cannot be used here'];
        yield 'case alone' => ['case (a)', 'Cannot find switch'];
        yield 'endswitch alone' => ['endswitch', 'Cannot find switch'];
        yield 'a step before the first case' => ["switch (a)\n:b;", "No 'case' in this switch"];
        yield 'kill a switch without cases' => ["switch (a)\nkill", 'kill cannot be used here'];
        yield 'a swimlane after a step' => [":a;\n|Lane|", 'This swimlane must be defined at the start of the diagram.'];
        yield 'a swimlane after a step in a split' => ["split\n  :a;\nend split\n|Lane|", 'This swimlane must be defined at the start of the diagram.'];
        yield 'kill a break' => ["while (a)\n  break\n  kill\nendwhile", 'kill cannot be used here'];
    }

    public function testAListComesInByTheArrowIntoItsFirstStep(): void
    {
        $this->assertSame(['in'], self::read(":a;\n-> in;\n:b;")->root->all[1]->inLink()->display);
        $this->assertNull(self::read(":a;\n:b;")->root->inLink()->display);
    }

    #[DataProvider('refused')]
    public function testWhatPlantUmlFailsOnIsRefused(string $lines, string $message): void
    {
        try {
            self::read($lines);
            $this->fail('Expected a refusal');
        } catch (Unsupported $refusal) {
            $this->assertSame($message, $refusal->getMessage());
            $this->assertInstanceOf(PlantUmlRefusal::class, $refusal->getPrevious());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupported(): iterable
    {
        yield 'goto' => ["label a\ngoto a", 'The native PlantUML renderer does not draw: label a'];
    }

    #[DataProvider('unsupported')]
    public function testWhatIsNotDrawnNativelyIsLeftToPlantUml(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($lines);
    }

    public function testOnlyAListOfASingleEndingIsAnEndingAlone(): void
    {
        $lists = [];
        foreach (['stop', 'end', 'start', "stop\nnote : n", ":a;\nkill", ':a;', '(A)', ":a;\n:b;", "if (b) then\nendif", 'note : x'] as $lines) {
            $if = self::read("if (a) then\n".$lines."\nendif")->root->all[0];
            $this->assertInstanceOf(InstructionIf::class, $if);
            $lists[] = $if->thens[0]->list->onlySingleStopOrSpot();
        }

        $this->assertSame([true, true, false, false, true, false, true, false, false, false], $lists);
    }

    private static function read(string $lines): PlantUmlActivity
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlActivity::class, $diagram);

        return $diagram;
    }
}
