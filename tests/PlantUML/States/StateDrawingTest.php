<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\States;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\States\PlantUmlStates;
use Janalis\Viz\PlantUML\States\StateBox;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class StateDrawingTest extends TestCase
{
    public function testAClusteredCompositeAndAHistoryStandAsPlantUmlDrawsThem(): void
    {
        $baselines = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\n[*] --> Player\nstate Player {\n  [*] --> Stopped\n}\nPlayer --> Off\nOff --> Player[H]\n@enduml"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $baselines[$mark->text] = \round($mark->points[0][1] / Units::POINT, precision: 3);
        }

        // As the jar draws them: the cluster's name 4 below its top, the history's letter at 14 points, centred.
        $this->assertSame([98.535, 140.291], [$baselines['Player'], $baselines['H']]);
    }

    public function testALabelAndItsNoteStandAsPlantUmlDrawsThem(): void
    {
        $baselines = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\n[*] --> Locked\nLocked --> Open : coin\nnote on link\n  pays\nend note\nOpen --> Locked : push\n@enduml"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $baselines[\trim($mark->text)] = $mark->points[0][1] / Units::POINT;
        }

        // As the jar draws them: from the corner of the box svek writes in whole points, the note 1 below its label.
        $this->assertEqualsWithDelta(['coin' => 180.568, 'pays' => 206.879, 'push' => 198.068], \array_intersect_key($baselines, ['coin' => 0, 'pays' => 0, 'push' => 0]), 0.005);
    }

    public function testTheDiagramIsAsTallAsPlantUmlMakesIt(): void
    {
        $height = static fn (string $lines): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"))->height / Units::POINT;

        // As the jar sizes them: the start's and the end's circles from their near sides, a label's room in whole points.
        $this->assertSame([150.0, 153.0], [$height('[*] --> A'), $height('A --> [*]')]);
        $this->assertSame(361.0, $height("[*] --> Idle\nIdle --> Running : start\nRunning --> Idle : stop\nRunning --> [*]"));
        // 251.31 its lowest box's foot, a point short, and 15: 265.31, rounded down.
        $this->assertSame(265.0, $height("[*] --> Idle\nIdle --> Busy\nnote \"Shared note\" as N1"));
    }

    public function testStatesAndPseudostatesTakePlantUmlsSizes(): void
    {
        $states = self::read("state Idle\nstate Busy : working hard on it\n[*] --> Idle\nIdle --> [*]\nIdle --> [H]\nstate C <<choice>>\nstate F <<fork>>\nstate D <<history*>>\nstate C2");
        $size = static fn (string $id): array => [StateBox::of($states->nodes[$id], $states)->width, StateBox::of($states->nodes[$id], $states)->height];

        $this->assertSame([\max(50.0, (4 * Units::cell(14.0)) + 20), 50.0], $size('Idle'), 'at least 50 by 50');
        $this->assertSame([50.0, 50.0], $size('C2'));
        $this->assertEqualsWithDelta([(18 * Units::cell(14.0)) + 20, (2 * PlantUmlText::lineHeight(14.0)) + 20], $size('Busy'), 1e-9);
        $this->assertSame([[20.0, 20.0], [22.0, 22.0], [22.0, 22.0], [24.0, 24.0], [80.0, 8.0], [22.0, 22.0]], [$size('*start*'), $size('*end*'), $size('*h*'), $size('C'), $size('F'), $size('D')]);
        $hidden = self::read("hide empty description\nstate Idle\nleft to right direction\nstate F <<join>>");
        $this->assertSame([\max(50.0, (4 * Units::cell(14.0)) + 10), 40.0], [StateBox::of($hidden->nodes['Idle'], $hidden)->width, StateBox::of($hidden->nodes['Idle'], $hidden)->height]);
        $this->assertSame([8.0, 80.0], [StateBox::of($hidden->nodes['F'], $hidden)->width, StateBox::of($hidden->nodes['F'], $hidden)->height]);
    }

    public function testEveryKindDraws(): void
    {
        $drawing = self::draw(<<<'PUML'
            state Idle #back:pink;line:red
            state Busy : working
            [*] --> Idle : begin
            Idle --> Busy
            Busy --> [*]
            Idle --> [H]
            Idle --> [H*]
            state C <<choice>>
            Busy --> C
            C --> ==bar==
            note right of Busy : notable
            note "free" as N
            note on link : linked
            hide empty description
            PUML);

        $texts = self::texts($drawing);
        foreach (['Idle', 'Busy', 'working', 'begin', 'H', 'H*', 'notable', 'free', 'linked'] as $text) {
            $this->assertContains($text, $texts);
        }
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#ffc0cb' === $mark->fill && '#ff0000' === $mark->stroke));
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#222222' === $mark->fill), 'the start disc and the bar');
    }

    public function testACompositeNothingCrossesIntoIsLaidOutOnItsOwnWithItsRegions(): void
    {
        $drawing = self::draw(<<<'PUML'
            [*] --> Active
            state Active {
              [*] --> A
              note right of A : inner
              ||
              [*] --> B
              note "region note" as R
            }
            Active : described
            state Down {
              [*] --> X
              --
              [*] --> Y
            }
            Active --> Down
            PUML);

        $texts = self::texts($drawing);
        foreach (['Active', 'described', 'A', 'B', 'inner', 'region note', 'Down', 'X', 'Y'] as $text) {
            $this->assertContains($text, $texts);
        }
        $separators = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => [5.0 * Units::POINT, 5.0 * Units::POINT] === $mark->dashes));
        $this->assertCount(2, $separators);
        $this->assertSame($separators[0]->points[0][0], $separators[0]->points[1][0], '|| separates side by side');
        $this->assertSame($separators[1]->points[0][1], $separators[1]->points[1][1], '-- separates one above the other');
    }

    public function testACompositeATransitionCrossesIntoIsACluster(): void
    {
        $drawing = self::draw(<<<'PUML'
            state Outer {
              state Inner {
                state Deep
                note left of Deep : deep note
              }
              Inner --> Other
            }
            Outer : outer text
            Deep --> Away
            Outer --> Away
            Away --> Inner
            PUML);

        $texts = self::texts($drawing);
        foreach (['Outer', 'outer text', 'Inner', 'Deep', 'deep note', 'Other', 'Away'] as $text) {
            $this->assertContains($text, $texts);
        }

        // A cluster no transition starts or ends at has no point of its own for one to end at.
        $this->assertContains('Kept', self::texts(self::draw("state Box {\n  state Kept\n}\nAway --> Kept")));

        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw concurrent regions a transition crosses into'));
        self::draw("state Split {\n  state P\n  --\n  state Q\n}\nP --> Out");
    }

    private static function read(string $lines): PlantUmlStates
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlStates::class, $diagram);

        return $diagram;
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }

    /** @return list<string> */
    private static function texts(Drawing $drawing): array
    {
        return \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
    }
}
