<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\States;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\States\PlantUmlStates;
use Janalis\Viz\PlantUML\States\StateBox;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class StateDrawingTest extends TestCase
{
    public function testAStateNamedByItsCompositeStatesNameIsThatState(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")),
        );

        // As the jar draws them: `A.B` the state B in A, linked to or declared again.
        $this->assertSame([[108.0, 208.0], [104.0, 216.0], [108.0, 122.0]], [
            $size("state A {\n  state B\n  state C\n}\nA.B --> C"),
            $size("state A {\n  state B\n}\nA.B --> X"),
            $size("state A {\n  state B\n}\nstate A.B"),
        ]);
    }

    public function testStatesAreMadeAsPlantUmlMakesThemDeclaredFirstThenByTheirTransitions(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")),
        );

        // As the jar draws them: a state a transition names before it is declared in a composite state the one in it; a floating note before the states only transitions name.
        $this->assertSame('C', self::read("A --> B\nstate C {\n state B\n}")->nodes['B']->group);
        $this->assertSame([187.0, 265.0], $size("[*] --> Idle\nIdle --> Busy\nnote \"Shared note\" as N1"));
    }

    public function testAFrameIsDrawnRoundItsStatesAsPlantUmlDrawsIt(): void
    {
        $drawing = self::draw("frame F {\n state B\n state C\n}\nframe \"Long frame title\" as G {\n frame H {\n  state D\n }\n}\nB --> D");
        $tabs = \array_values(\array_map(
            static fn (Mark $mark): array => \array_map(static fn (array $point): array => [
                \round($point[0] / Units::POINT, precision: 2),
                \round($point[1] / Units::POINT, precision: 2),
            ], $mark->points),
            \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && 4 === \count($mark->points)),
        ));

        // As the jar draws them: clusters with a state's corners, each name in bold in a tab 10 wider and 3 taller than it.
        $this->assertSame([212.0, 314.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertSame([[49.04, 7.0], [49.04, 16.49], [39.04, 26.49], [31.0, 26.49]], $tabs[0]);
        $this->assertSame('G', self::read("frame \"Long frame title\" as G {\n frame H {\n  state D\n }\n}")->nodes['H']->group);
    }

    public function testAJsonEntityIsDrawnAsAClassDiagramDrawsIt(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")),
        );

        // As the jar draws them: its data as a class diagram's, beside or under a state, in a composite state too.
        $this->assertSame([[72.0, 176.0], [152.0, 71.0], [171.0, 134.0]], [
            $size("state A\njson J {\n \"a\": 1\n}\nA --> J"),
            $size("json J {\n \"a\": 1\n}\nstate A"),
            $size("state S {\n  state A\n  json J {\n   \"k\": [1, 2]\n  }\n}"),
        ]);
        $this->expectExceptionMessage('JSON already exists: J');
        $size("state A\njson J {\n}\njson J {\n}");
    }

    public function testAnSdlReceiveStateIsDrawnAsAFrame(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")),
        );

        // As the jar draws them: a frame round its name, its description left out; with its empty description hidden, or another stereotype, a state.
        $this->assertSame([[89.0, 177.0], [104.0, 83.0], [71.0, 161.0], [72.0, 71.0]], [
            $size("state S1 <<sdlreceive>>\nS1 : text\nstate S2\nS1 --> S2"),
            $size("state \"Two\\nlines\" as S1 <<sdlreceive>>"),
            $size("hide empty description\nstate S1 <<sdlreceive>>\nstate S2\nS1 --> S2"),
            $size('state S1 <<sdlReceive>>'),
        ]);
    }

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

    public function testACompositeStatesAnchorIsTakenAsPlantUmlTakesIt(): void
    {
        $width = static fn (string $lines): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"))->width / Units::POINT;
        $player = "state Player {\n  Stopped --> Playing\n  Playing --> Paused\n  Paused --> Playing\n}\nPlayer --> Off\nOff --> Player[H]";

        // As the jar sizes them: the anchor a hundredth of an inch across, and taken 14 either side.
        $this->assertSame(227.0, $width("[*] --> Player\n".$player));
        $this->assertSame(213.0, $width("state P {\n  state Q\n  Q --> R\n}\nA --> P\nP --> B\nB --> P[H]"));
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

        $this->assertSame([\max(50.0, PlantUmlMetrics::width('Idle', 14.0) + 20), 50.0], $size('Idle'), 'at least 50 by 50');
        $this->assertSame([50.0, 50.0], $size('C2'));
        $this->assertEqualsWithDelta([PlantUmlMetrics::width('working hard on it', 14.0) + 20, (2 * PlantUmlText::lineHeight(14.0)) + 20], $size('Busy'), 1e-9);
        $this->assertSame([[20.0, 20.0], [22.0, 22.0], [22.0, 22.0], [24.0, 24.0], [80.0, 8.0], [22.0, 22.0]], [$size('*start*'), $size('*end*'), $size('*h*'), $size('C'), $size('F'), $size('D')]);
        $hidden = self::read("hide empty description\nstate Idle\nleft to right direction\nstate F <<join>>");
        $this->assertSame([\max(50.0, PlantUmlMetrics::width('Idle', 14.0) + 10), 40.0], [StateBox::of($hidden->nodes['Idle'], $hidden)->width, StateBox::of($hidden->nodes['Idle'], $hidden)->height]);
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
        $separators = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => [8.0 * Units::POINT, 10.0 * Units::POINT] === $mark->dashes));
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

        // Its concurrent regions as svek lays them out, a region no transition crosses into an image in the cluster: as the jar draws it, 205 by 231.
        $split = self::draw("state Split {\n  state P\n  --\n  state Q\n}\nP --> Out");
        $this->assertSame([205.0, 231.0], [$split->width / Units::POINT, $split->height / Units::POINT]);
        $nested = self::draw("state Split {\n  state N {\n    state D\n  }\n  --\n  state Q\n}\nD --> Out");
        $this->assertSame([229.0, 266.0], [$nested->width / Units::POINT, $nested->height / Units::POINT]);
    }

    public function testPointsOnACompositesBorderStandAsPlantUmlDrawsThem(): void
    {
        $drawing = self::draw("state Somp {\n  state entry1 <<entryPoint>>\n  state exitA <<exitPoint>>\n  entry1 --> B\n  B --> exitA\n}\n[*] --> entry1\nexitA --> Foo");
        $at = static fn (Mark $mark): array => [
            \round(\min(\array_column($mark->points, 0)) / Units::POINT, precision: 3),
            \round(\min(\array_column($mark->points, 1)) / Units::POINT, precision: 3),
            \round(\max(\array_column($mark->points, 0)) / Units::POINT, precision: 3),
            \round(\max(\array_column($mark->points, 1)) / Units::POINT, precision: 3),
        ];
        $circles = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && (1.5 * Units::POINT) === $mark->strokeWidth));
        $texts = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $texts[$mark->text] = \round($mark->points[0][1] / Units::POINT, precision: 3);
        }

        // As the jar draws them: the frame through the points, its name 5 below its top; each point a circle 12 across, its name 12 above or below it, the exit's crossed.
        $this->assertSame([104.0, 428.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertSame([7.0, 104.0, 89.0, 298.0], $at($drawing->marks[0]));
        $this->assertSame(
            [
                [42.0, 98.0,  54.0, 110.0],
                [42.0, 292.0, 54.0, 304.0],
            ],
            \array_map($at, $circles),
        );
        $this->assertSame([122.535, 83.047, 317.535], [$texts['Somp'], $texts['entry1'], $texts['exitA']]);
        $this->assertCount(2, \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && (1.5 * Units::POINT) === $mark->strokeWidth));

        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };
        // A long name makes the point a port in a table, the point read from the port's column; a composite holding nothing else holds a point.
        $this->assertSame([236.0, 262.0], $size("state S {\n state \"a very long entry point name\" as e1 <<entryPoint>>\n e1 --> A\n}\n[*] --> e1"));
        $this->assertSame([61.0, 188.0], $size("state S {\n state e1 <<entryPoint>>\n}\n[*] --> e1"));
        $this->assertSame([104.0, 187.0], $size("state S {\n state e1 <<entryPoint>>\n e1 --> A\n}"));
        $this->assertSame([376.0, 122.0], $size("left to right direction\nstate S {\n state e1 <<entryPoint>>\n state x1 <<exitPoint>>\n e1 --> A\n A --> x1\n}\n[*] --> e1\nx1 --> [*]"));
        $this->assertSame(
            [189.0, 376.0],
            $size("state S {\n state e1 <<entryPoint>>\n state e2 <<entryPoint>>\n state x1 <<exitPoint>>\n e1 --> A\n e2 --> B\n A --> x1\n B --> x1\n}\n[*] --> e1\n[*] --> e2\nx1 --> [*]"),
        );
        $this->assertSame([237.0, 238.0], $size("state \"Composite with a long title\" as S {\n state e1 <<entryPoint>>\n e1 --> A\n}\n[*] --> e1"));
        $this->assertSame([213.0, 466.0], $size("state S {\n state e1 <<entryPoint>>\n e1 --> A\n A --> B\n B --> C\n}\n[*] --> e1\nS --> D"));

        // Turned, the long title spreads the ranks inside; two composites each rank their own points.
        $this->assertSame(
            [537.0, 122.0],
            $size("left to right direction\nstate \"A rather long composite title\" as S {\n state e1 <<entryPoint>>\n state x1 <<exitPoint>>\n e1 --> A\n A --> x1\n}\n[*] --> e1\nx1 --> [*]"),
        );
        $this->assertSame([194.0, 238.0], $size("state S {\n state e1 <<entryPoint>>\n e1 --> A\n}\nstate T {\n state e2 <<entryPoint>>\n e2 --> B\n}\n[*] --> e1\n[*] --> e2"));

        // A frame narrower than its title is widened for it.
        $this->assertSame([227.0, 188.0], $size("state \"A rather long composite title\" as S {\n state e1 <<entryPoint>>\n state e2 <<entryPoint>>\n}\n[*] --> e1\n[*] --> e2"));
        // Numbered states stand as any others.
        $this->assertSame([104.0, 376.0], $size("state 1 {\n state 2 <<entryPoint>>\n state 4 <<exitPoint>>\n 2 --> 3\n 3 --> 4\n}\n[*] --> 2\n4 --> [*]"));

        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer draws points and pins only on a composite state a transition crosses into'));
        self::draw("state p1 <<entryPoint>>\nA --> p1");
    }

    public function testALinkPassingBesideAStateRunsAsDotFitsItsSpline(): void
    {
        // As the jar draws it, the link up from C to A runs close beside B, as dot fits it through the room by its virtual node, not out through the node.
        $drawing = self::draw("state A\nstate B\nA --> B\nB --> C : go\nC -up-> A");
        $this->assertSame([111.0, 309.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testPinsAndExpansionsStandOnACompositesBorderAsPlantUmlDrawsThem(): void
    {
        $at = static fn (Mark $mark): array => [
            \round(\min(\array_column($mark->points, 0)) / Units::POINT, precision: 3),
            \round(\min(\array_column($mark->points, 1)) / Units::POINT, precision: 3),
            \round(\max(\array_column($mark->points, 0)) / Units::POINT, precision: 3),
            \round(\max(\array_column($mark->points, 1)) / Units::POINT, precision: 3),
        ];
        $thick = static fn (Drawing $drawing, MarkKind $kind): array => \array_values(\array_map($at, \array_filter(
            $drawing->marks,
            static fn (Mark $mark): bool => $kind === $mark->kind && (1.5 * Units::POINT) === $mark->strokeWidth,
        )));

        // As the jar draws them: a pin a square 12 across, an expansion four in a row with three dividers, each written to svek as a plain rectangle its size.
        $pins = self::draw("state Somp {\n  state in1 <<inputPin>>\n  state out1 <<outputPin>>\n  in1 --> A\n  A --> out1\n}\n[*] --> in1\nout1 --> [*]");
        $this->assertSame([104.0, 376.0], [$pins->width / Units::POINT, $pins->height / Units::POINT]);
        $this->assertSame(
            [
                [42.0, 86.0,  54.0, 98.0],
                [42.0, 268.0, 54.0, 280.0],
            ],
            $thick($pins, MarkKind::Polygon),
        );
        $expansions = self::draw("state Somp {\n  state ex1 <<expansionInput>>\n  state ex2 <<expansionOutput>>\n  ex1 --> A\n  A --> ex2\n}\n[*] --> ex1\nex2 --> [*]");
        $this->assertSame([104.0, 376.0], [$expansions->width / Units::POINT, $expansions->height / Units::POINT]);
        $this->assertSame(
            [
                [24.0, 86.0,  72.0, 98.0],
                [24.0, 268.0, 72.0, 280.0],
            ],
            $thick($expansions, MarkKind::Polygon),
        );
        $this->assertSame([36.0, 86.0, 36.0, 98.0], $thick($expansions, MarkKind::Polyline)[0]);
        $names = \array_values(\array_filter($expansions->marks, static fn (Mark $mark): bool => 'ex1' === $mark->text));
        $this->assertEqualsWithDelta(17.381, ($names[0]->points[0][0] / Units::POINT) - (25.238 / 2), 0.01);

        // Turned, an expansion stands upright, its dividers across; a long name widens the drawing.
        $turned = self::draw("left to right direction\nstate Somp {\n  state ex1 <<expansionInput>>\n  state ex2 <<outputPin>>\n  ex1 --> A\n  A --> ex2\n}\n[*] --> ex1\nex2 --> [*]");
        $this->assertSame([376.0, 122.0], [$turned->width / Units::POINT, $turned->height / Units::POINT]);
        $this->assertSame(
            [
                [86.0,  43.0, 98.0,  91.0],
                [268.0, 61.0, 280.0, 73.0],
            ],
            $thick($turned, MarkKind::Polygon),
        );
        $this->assertSame([86.0, 55.0, 98.0, 55.0], $thick($turned, MarkKind::Polyline)[0]);
        $long = self::draw("state Somp {\n  state inputWithAVeryLongName <<inputPin>>\n  inputWithAVeryLongName --> A\n}\n[*] --> inputWithAVeryLongName");
        $this->assertSame([198.0, 238.0], [$long->width / Units::POINT, $long->height / Units::POINT]);
        $this->assertSame([436.0, 156.0], [
            ($wide = self::draw(
                "left to right direction\nstate Big {\n  state p1 <<inputPin>>\n  state p2 <<inputPin>>\n  state x1 <<expansionOutput>>\n  p1 --> A\n  p2 --> A\n  A --> x1\n}\nS1 --> p1\nS2 --> p2\nx1 --> E",
            ))->width / Units::POINT,
            $wide->height / Units::POINT,
        ]);
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

    public function testRegionsSideBySideStandInFromTheLeftUnderASeparatorADashLong(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nstate A {\n  state B\n  ||\n  state C\n}\n@enduml"));

        // As the jar draws it, 174 by 126: the regions 5 in from the left, the separator 8 past their foot.
        $this->assertSame([174.0, 126.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testFlatTransitionsBetweenTheSameStatesStandOneAboveAnother(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nstate A\nstate B\nA -> B\nA -> C\nB -> C\nC -> A\n@enduml"));

        // As the jar draws it, 116 tall: the second transition over B a third of the rank separation above the first.
        $this->assertSame([242.0, 116.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testHiddenStatesKeepTheirPlaceAndRemovedOnesGo(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: a state hidden laid out, drawn with nothing that touches it, its note neither; one removed taken out with its transitions and notes.
        $this->assertSame([72.0, 291.0], $size("state A\nstate B\nA --> B\nB --> C\nhide B"));
        $this->assertSame([157.0, 71.0], $size("state A\nstate B\nA --> B\nB --> C\nremove B"));
        $this->assertSame([136.0, 291.0], $size("state A <<x>>\nA --> B\nB --> C\nhide <<x>>\nnote right of A : n"));
        $this->assertSame([72.0, 260.0], $size("[*] --> A\nA --> B\nstate Z\nremove @unlinked"));
    }

    public function testRemovedStatesAreLeftOutOfTheModel(): void
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\nstate A\nstate B\nA --> B\nB --> C\nnote right of B : n\nnote left of A : m\nremove B\n@enduml");
        $this->assertInstanceOf(PlantUmlStates::class, $diagram);
        $model = $diagram->model();

        $this->assertSame(['A', 'C'], \array_map(static fn (State $state): string => $state->id, $model->states));
        $this->assertSame([], $model->transitions);
        $this->assertCount(1, $model->notes);
    }
}
