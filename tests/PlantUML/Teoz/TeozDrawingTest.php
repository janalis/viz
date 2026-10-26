<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Teoz;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeozDrawingTest extends TestCase
{
    /** The frame's margin around the diagram, in points. */
    private const float MARGIN = 10.0;

    public function testTwoParticipantsAndAMessageStandAsPlantUmlSetsThem(): void
    {
        $marks = self::points(self::draw('A -> B'));
        // A head is its name, a line of PlantUML's sans-serif, 7 padding around it, and 1 more down; B stands 10 clear of A.
        $headWidth = PlantUmlMetrics::width('A', 14.0) + 14;
        $otherWidth = PlantUmlMetrics::width('B', 14.0) + 14;
        $headHeight = ((14.0 * 603) / 512) + 14 + 1;
        $this->assertEqualsWithDelta([0.0, 0.0], self::topLeft(self::rectangles($marks)[0]), 1e-9);
        $this->assertEqualsWithDelta([$headWidth + 10, 0.0], self::topLeft(self::rectangles($marks)[1]), 1e-9);
        // The arrow, 8 below the heads and 6 down its tile, runs from A's lifeline to 6 short of B's, its tip 2 short.
        $arrowY = $headHeight + 8 + 6;
        $line = self::lineAt($marks, $arrowY);
        $this->assertEqualsWithDelta([[$headWidth / 2, $arrowY], [$headWidth + 10 + ($otherWidth / 2) - 6, $arrowY]], $line->points, 1e-9);
        $tip = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 4 === \count($mark->points)))[0];
        $this->assertEqualsWithDelta($headWidth + 10 + ($otherWidth / 2) - 2, $tip->points[1][0], 1e-9);
        // The lifelines run 8 + 14 + 10 down between the heads and the feet.
        $lifeline = \array_values(\array_filter($marks, static fn (Mark $mark): bool => null !== $mark->dashes))[0];
        $this->assertEqualsWithDelta([[$headWidth / 2, $headHeight], [$headWidth / 2, $headHeight + 32]], $lifeline->points, 1e-9);
        $this->assertEqualsWithDelta([5.0, 5.0], $lifeline->dashes, 1e-9);
    }

    public function testAnActivationPushesTheLifelineItEndsOnFiveRight(): void
    {
        $plain = self::points(self::draw('A -> B : hello world'));
        $active = self::points(self::draw("A -> B : hello world\nactivate B"));

        $this->assertEqualsWithDelta(5.0, self::rectangles($active)[1][0][0] - self::rectangles($plain)[1][0][0], 1e-9);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function diagrams(): iterable
    {
        yield 'participants of every kind, boxed' => [<<<'PUML'
                box "Front" #lightblue
                  actor Bob <<human>>
                  boundary Gate
                end box
                box "Back"
                  control Ctl
                  entity Ent
                  box "Store"
                    database Db #pink
                    queue Q
                    collections Col
                  end box
                end box
                participant P
                Bob -> Gate : in
                Gate -> Ctl
                Ctl -> Ent
                Ent -> Db
                Db -> Q
                Q -> Col
                Col -> P
                hide footbox
                PUML, ['«human»', 'Bob', 'Gate', 'Ctl', 'Ent', 'Db', 'Q', 'Col', 'P', 'Front', 'Back', 'Store']];
        yield 'arrows of every kind' => [<<<'PUML'
                participant A
                participant B
                A ->> B : open
                A -\ B : half top
                A -/ B : half bottom
                A ->o B : circle
                A o->o B : circles
                A x-> B : cross start
                A o-->> B : dotted
                A <-> B : both
                A <<-->> B : both open
                A -[#red]> B : red
                A -[hidden]> B : hidden
                A -> B : slanted
                A ->(10) B : slanted
                B <- A : written backwards
                B o<-o A : circles backwards
                B x<- A : crossed backwards
                A ->x B : crossed
                B -\\ A : half open top
                B -// A : half open bottom
                PUML, ['open', 'half top', 'circle', 'cross start', 'both', 'red', 'written backwards']];
        yield 'self messages' => [<<<'PUML'
                participant A
                participant B
                A -> A : plain
                A --> A : dotted
                A ->> A : open
                A -\ A : half
                A -/ A : half
                A -\\ A : half open
                A -// A : half open
                A o->o A : circles
                A x-> A : crossed start
                A ->x A : crossed
                A <- A : leftward
                A <-o A : leftward circle
                A o<- A : leftward circle back
                A x<- A : leftward crossed
                A <-x A : leftward crossed back
                A <<- A : leftward open
                A <-\ A : leftward half
                A <-/ A : leftward half
                A <-> A : both
                B -> B
                PUML, ['plain', 'leftward', 'both']];
        yield 'activations and their levels' => [<<<'PUML'
                participant A
                participant B
                A -> B ++ #gold : one
                B -> B ++ : nested
                B -> A : back
                A -> B : deeper
                activate B #pink
                B <- B : leftward
                B -> B -- : out
                B --> A -- : done
                A -> B ** : create
                B -> A !! : destroy
                A -> B : between
                activate A
                A -> A : self active
                A -> A ++ : self more
                A <- A : left active
                deactivate A
                deactivate A
                note right of A : after
                destroy A
                PUML, ['one', 'nested', 'destroy', 'after']];
        yield 'messages from and to the edges' => [<<<'PUML'
                participant A
                participant B
                [-> A : from left
                A ->] : to right
                [<- A : to left
                A <-] : from right
                ?-> A : short from left
                A ->? : short to right
                [->> A ++ : open
                A ->] -- : deactivate
                [<-> B : both
                [--> B !! : dotted
                [-> A
                & A -> B : parallel
                [-> A : noted
                note left : on the left
                note right : on the right
                [-> A : top
                note top : ignored
                PUML, ['from left', 'to right', 'short from left', 'parallel', 'on the left', 'on the right']];
        yield 'notes' => [<<<'PUML'
                participant A
                participant B
                participant C
                note left of A : left
                note right of A : right
                note over A : over
                note over A, C : several
                note across : across
                hnote over B : hexagon
                rnote over B #pink : box
                note over A : first
                / note over C : beside
                / note left of A : stacked left
                / note left of A : stacked more
                / note right of A : stacked right
                / note over A, B : several beside
                A -> B : message
                note left : on its left
                note right #yellow : on its right
                note top : over it
                note bottom : under it
                B -> A : back
                note left : left of back
                note right : right of back
                A -> A : loop
                note left : left of loop
                A -> A : loop again
                note right : right of loop
                A <- A : loop back
                note left : left of loop back
                create C
                A -> C : create
                note right : by the new one
                PUML, ['left', 'hexagon', 'box', 'beside', 'stacked more', 'over it', 'under it', 'right of loop', 'by the new one']];
        yield 'groups' => [<<<'PUML'
                participant A
                participant B
                participant C
                participant D
                alt#gold #lightgrey success
                  A -> B : ok
                  note right of B : noted
                else #pink failure
                  A -> B : ko
                  group custom [label]
                    B -> C : inner
                    loop 10 times
                      C -> C : again
                      note left : looping
                    end
                  end
                else
                  B -> B
                  ||45||
                  A <- A
                  ref over B : referenced
                end
                group
                  A -> B
                end
                par
                  A -> B
                also
                  A -> C
                end
                opt
                end
                group many [things]
                  B -> D
                  note over C : over C
                end
                critical
                  ...
                  A -> B
                end
                PUML, ['alt', '[success]', '[failure]', 'custom', '[label]', 'loop', '[10 times]', 'group', 'par', 'opt']];
        yield 'dividers, delays, space and references' => [<<<'PUML'
                participant A
                participant B
                participant C
                A -> B ++ : before
                == Phase ==
                ...
                ... a while later ...
                |||
                ||50||
                ==  ==
                ref over A, C : spanning
                ref over B
                  one
                  two
                end ref
                B -> C : after
                deactivate B
                PUML, ['Phase', 'a while later', 'ref', 'spanning', 'one', 'two', 'after']];
        yield 'parallel messages and groups' => [<<<'PUML'
                participant A
                participant B
                participant C
                participant D
                A -> B : first
                & C -> D : alongside
                & B -> C : and this
                A -> B : again
                & D -> C : backwards
                group one
                  A -> B
                end
                & group two
                  C -> D
                end
                & D <- C : last
                A -> A ++
                & B -> B
                PUML, ['first', 'alongside', 'backwards', 'one', 'two', 'last']];
        yield 'creation and destruction' => [<<<'PUML'
                participant A
                A -> B ** : create
                B -> A
                destroy B
                create C
                C <- A : made backwards
                A -> D ** : created
                D -> D : busy
                destroy D
                PUML, ['create', 'made backwards', 'created']];
        yield 'autonumbered and styled' => [<<<'PUML'
                autonumber 10 5 "<b>[000]"
                skinparam sequenceMessageAlign center
                skinparam ParticipantMinimumWidth 100
                participant A
                participant B
                A -> B : numbered
                A -> B
                skinparam roundcorner 10
                title Titled
                PUML, ['numbered', 'Titled']];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function corners(): iterable
    {
        yield 'labels centred, references right' => [<<<'PUML'
                <style>
                sequenceDiagram { arrow { HorizontalAlignment center } reference { HorizontalAlignment right } }
                </style>
                A -> B : centred
                ref over A, B : right
                PUML, ['centred', 'right']];
        yield 'labels right, references left' => [<<<'PUML'
                <style>
                sequenceDiagram { arrow { HorizontalAlignment right } reference { HorizontalAlignment left } }
                </style>
                A -> B : right
                B -> A : back
                ref over A : left
                PUML, ['right', 'back', 'left']];
        yield 'icons with their feet' => ["actor Bob\nboundary Gate\ncontrol Ctl\nentity Ent\ndatabase Db\nqueue Q\nBob -> Q", ['Bob', 'Q']];
        yield 'a destruction on its own' => ["A -> B\n== d ==\ndestroy B\nA -> A", ['d']];
        yield 'created on the left' => ["participant Z\nparticipant A\nA -> Z ** : made\nnote right : noted", ['made', 'noted']];
        yield 'open both ways to itself' => ['A <<->> A : both', ['both']];
        yield 'activated and deactivated by one message' => ["A -> A ++\ndeactivate A\nA -> B\nnote right of A : n\nactivate B", ['n']];
        yield 'deactivated, then activated by the message alongside' => ["A -> B ++\nA -> B\ndeactivate B\n& A -> B\nactivate B", []];
        yield 'notes before an activation' => ["A -> B\nnote right of A : one\nnote right of A : two\nactivate A\nA -> B", ['one', 'two']];
        yield 'delays around an activation' => ["A -> B\n...\nA -> B ++\nB -> A --\n...\nA -> B\n...\nA -> B\nA -> C ** : made", ['made']];
        yield 'parallel groups of notes' => ["participant A\nparticipant B\ngroup a\nnote over A : x\nend\n& group b\nnote over B : y\nend", ['x', 'y']];
        yield 'groups in depth' => [<<<'PUML'
                participant A
                participant B
                participant C
                group outer
                  group inner
                    A -> B
                  end
                  & A -> B : alongside a group
                  == d ==
                  & A -> B : after a divider
                  activate B
                  alt x
                    A -> B
                  else y
                  & B -> A : after an else
                  end
                  group empty
                    == only ==
                  end
                  note left of A : left of A
                end
                group with a note across
                  note across : across
                end
                C -> C
                PUML, ['alongside a group', 'after an else', 'across', 'left of A']];
        yield 'a box wider than its participant' => ["box \"A very very very long box title\"\nparticipant A\nend box\nparticipant B\nA -> B", ['A very very very long box title']];
    }

    /** @param list<string> $texts */
    #[DataProvider('corners')]
    public function testCornerCasesDraw(string $lines, array $texts): void
    {
        $this->testEveryFeatureDraws($lines, $texts);
    }

    /** @param list<string> $texts */
    #[DataProvider('diagrams')]
    public function testEveryFeatureDraws(string $lines, array $texts): void
    {
        $drawing = self::draw($lines);

        $shown = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
        foreach ($texts as $text) {
            $this->assertContains($text, $shown);
        }
        foreach ($drawing->marks as $mark) {
            foreach ($mark->points as [$x, $y]) {
                $this->assertGreaterThanOrEqual(-1e-6, $x);
                $this->assertGreaterThanOrEqual(-1e-6, $y);
            }
        }
    }

    public function testADiagramWithoutParticipantsIsLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw sequence diagrams without participants'));

        self::draw('== alone ==');
    }

    public function testASecondNoteBesideALoopIsLeftToPlantUmlWhichFailsOnIt(): void
    {
        $this->expectExceptionObject(new Unsupported('PlantUML fails on a note beside a message to itself after another note on it'));

        self::draw("A -> A\nnote top : one\nnote right : two");
    }

    public function testNotesAcrossBesideAnotherAreLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw a note across the diagram beside another'));

        self::draw("participant A\nnote over A : one\n/ note across : two");
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }

    /**
     * The drawing's marks back in points from its frame's margin.
     *
     * @return list<Mark>
     */
    private static function points(Drawing $drawing): array
    {
        return \array_map(static fn (Mark $mark): Mark => new Mark(
            $mark->kind,
            \array_map(static fn (array $point): array => [($point[0] / Units::POINT) - self::MARGIN, ($point[1] / Units::POINT) - self::MARGIN], $mark->points),
            $mark->text,
            $mark->stroke,
            $mark->fill,
            $mark->dashed,
            $mark->strokeWidth,
            dashes: null === $mark->dashes ? null : \array_map(static fn (float $length): float => $length / Units::POINT, $mark->dashes),
        ), $drawing->marks);
    }

    /**
     * The participants' head boxes, in order.
     *
     * @param list<Mark> $marks
     * @return list<list<array{float, float}>>
     */
    private static function rectangles(array $marks): array
    {
        return \array_values(\array_map(
            static fn (Mark $mark): array => $mark->points,
            \array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#e2e2f0' === $mark->fill),
        ));
    }

    /**
     * @param list<array{float, float}> $points
     * @return array{float, float}
     */
    private static function topLeft(array $points): array
    {
        return [\min(\array_column($points, 0)), \min(\array_column($points, 1))];
    }

    /** @param list<Mark> $marks */
    private static function lineAt(array $marks, float $y): Mark
    {
        foreach ($marks as $mark) {
            if (MarkKind::Polyline === $mark->kind && null === $mark->dashes && \abs($mark->points[0][1] - $y) < 1e-6 && \abs($mark->points[1][1] - $y) < 1e-6) {
                return $mark;
            }
        }
        self::fail('No line at '.$y);
    }

    public function testADividersAndAGroupsTitlesKeepTheSpaceTheyEndWith(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: `== Section ==` reads `Section `, `group G [label]` names its type `G `, each measured with its space.
        $this->assertSame([111.0, 173.0], $size("A -> B\n== Section ==\nB -> A"));
        $this->assertSame([158.0, 175.0], $size("A -> B\ngroup G [label]\n B -> A\nend"));
    }

    public function testTheActorStyleSetsHowAnActorStands(): void
    {
        $size = static function (string $style): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nskinparam actorStyle ".$style."\nactor A\nparticipant B\nA -> B\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: a head over shoulders 54 wide, 61 tall; a hollow figure 26 wide, 33 tall.
        $this->assertSame([108.0, 207.0], $size('awesome'));
        $this->assertSame([79.0, 151.0], $size('hollow'));
    }
}
