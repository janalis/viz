<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Activities;

use Janalis\Viz\Model\Activity\Action;
use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\Model\Activity\Branch;
use Janalis\Viz\Model\Activity\Decision;
use Janalis\Viz\Model\Activity\Fork;
use Janalis\Viz\Model\Activity\Loop;
use Janalis\Viz\Model\Activity\Note;
use Janalis\Viz\Model\Activity\Partition;
use Janalis\Viz\Model\Activity\Terminal;
use Janalis\Viz\Model\Activity\TerminalKind;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Source\PlantUmlActivityWriter;
use PHPUnit\Framework\TestCase;

final class ActivityModelTest extends TestCase
{
    public function testEveryStepMapsOntoTheSharedModel(): void
    {
        $model = self::model(<<<'PUML'
            title Flow
            note right : first of all
            start
            :one\ntwo;
            note left : beside
            (B)
            if (a?) then (yes)
              :x;
            elseif (b?) then (also)
              :y;
              kill
            else (no)
              :z;
            endif
            if (c?) then
            endif
            while (more?) is (go)
              :w;
            endwhile (done)
            stop
            repeat
              :r;
              break
            repeat while (again?) is (yes) not (no)
            fork
              :f;
            fork again
              :g;
            end fork
            split
              :s;
            split again
              :t;
            end split
            partition P {
              note : inside
              :p;
            }
            end
            PUML);

        $this->assertEquals(new ActivityDiagram([
            new Note('first of all'),
            new Terminal(TerminalKind::Start),
            new Action("one\ntwo"),
            new Note('beside', left: true),
            new Action('B'),
            new Decision(
                [new Branch('a?', 'yes', [new Action('x')]), new Branch('b?', 'also', [new Action('y'), new Terminal(TerminalKind::Detach)])],
                new Branch('', 'no', [new Action('z')]),
            ),
            new Decision([new Branch('c?', '', [])]),
            new Loop('more?', [new Action('w')], label: 'go', exit: 'done'),
            new Terminal(TerminalKind::Stop),
            new Loop('again?', [new Action('r')], repeat: true, label: 'yes', exit: 'no'),
            new Fork([[new Action('f')], [new Action('g')]]),
            new Fork([[new Action('s')], [new Action('t')]], split: true),
            new Partition('P', [new Note('inside', left: true), new Action('p')]),
            new Terminal(TerminalKind::End),
        ], 'Flow'), $model);
    }

    public function testTheModelWrittenBackReadsTheSame(): void
    {
        $source = "start\n:a;\nif (x) then (yes)\n  :b;\nelse (no)\n  :c;\nendif\nwhile (w) is (go)\n  :d;\nendwhile (out)\nrepeat\n  :e;\nrepeat while (r)\nfork\n  :f;\nfork again\n  :g;\nend fork\npartition \"P\" {\n  :h;\n}\nnote right : n\nstop";
        $model = self::model($source);

        $this->assertEquals($model, self::model(\trim(\substr(PlantUmlActivityWriter::write($model), offset: 9, length: -8))));
    }

    private static function model(string $lines): ActivityDiagram
    {
        $model = PlantUmlPipeline::native()
            ->diagram("@startuml\n".$lines."\n@enduml")
            ->model();
        self::assertInstanceOf(ActivityDiagram::class, $model);

        return $model;
    }
}
