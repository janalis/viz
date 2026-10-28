<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\LegacyActivities;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\LegacyActivities\LegacyEntity;
use Janalis\Viz\PlantUML\LegacyActivities\PlantUmlLegacyActivity;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyActivityReaderTest extends TestCase
{
    public function testLinksStartFromTheLastEntityWhenNoFirstIsWritten(): void
    {
        $diagram = self::read(<<<'PUML'
            (*) --> "First" <<blue>> #pink
            --> Second
            -right-> ===B1===
            ===B1=== -up-> "Third" as T
            T ..> [label] (*)
            T -*-> (*2)
            T -> "Fourth"
            PUML);

        $this->assertSame(['start', 'First', 'Second', 'B1', 'T', 'end', 'end$2', 'Fourth'], \array_keys($diagram->entities));
        $this->assertSame(
            ['start', 'activity', 'activity', 'bar', 'activity', 'end', 'end', 'activity'],
            \array_map(static fn (LegacyEntity $entity): string => $entity->kind, \array_values($diagram->entities)),
        );
        $this->assertSame([['Third'], '<<blue>>', '#pink'], [$diagram->entities['T']->display, $diagram->entities['First']->stereotype, $diagram->entities['First']->color]);
        $this->assertSame(
            [
                ['start',  'First',  2],
                ['First',  'Second', 2],
                ['Second', 'B1',     1],
                ['T',      'B1',     2],
                ['T',      'end',    2],
                ['T',      'end$2',  2],
                ['T',      'Fourth', 1],
            ],
            \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to, $link->length], $diagram->links),
        );
        $this->assertSame([LinkDecor::Arrow, LinkDecor::None], [$diagram->links[3]->fromDecor, $diagram->links[3]->toDecor], 'an arrow up is laid out reversed');
        $this->assertSame([true, 'label'], [$diagram->links[4]->dashed, $diagram->links[4]->label]);
        $this->assertTrue($diagram->links[5]->norank);
    }

    public function testDecisionsBranchAndReturnToTheirBranch(): void
    {
        $diagram = self::read(<<<'PUML'
            (*) --> "A"
            if "test?" then
              -->[yes] "B"
            else
              ->[no] "C"
              if "again" as again then
              endif
            endif
            "D" --> if "other" then
            endif
            (*) -> if "first" then
            endif
            ===X=== --> if "bar" then
            endif
            "E" as e --> if "quoted" then
            endif
            "F" -left-> [lab] if "g" then
            endif
            PUML);

        $branches = \array_values(\array_filter($diagram->entities, static fn (LegacyEntity $entity): bool => 'branch' === $entity->kind));
        $this->assertCount(7, $branches);
        $this->assertSame('again', $branches[1]->id);
        $tests = \array_values(\array_filter(\array_map(static fn (PlantUmlLink $link): ?string => $link->secondLabel, $diagram->links)));
        $this->assertSame(['test?', 'again', 'other', 'first', 'bar', 'quoted', 'g'], $tests);
        $this->assertSame($branches[0]->id, $diagram->links[2]->from, 'a link after an if starts from its branch');
        $this->assertSame($branches[0]->id, $diagram->links[3]->from, 'and after else again');
        $this->assertSame('===X===', $diagram->links[7]->from, 'a bar before an if is named with its equal signs, as PlantUML');
        $this->assertSame(['e', 'F'], [$diagram->links[8]->from, $diagram->links[9]->from]);
        $this->assertSame([1, 'lab'], [$diagram->links[9]->length, $diagram->links[9]->label]);
    }

    public function testPartitionsHoldWhatIsCreatedInThem(): void
    {
        $diagram = self::read(<<<'PUML'
            partition Outer <<s>> {
              (*) --> "A"
              partition "Inner"
                --> "B"
              end partition
            }
            "A" --> "C" in Elsewhere
            --> "D" in Outer
            -d-> "multi
            long
            line" as M <<x>> in Outer #yellow
            --> "
            more"
            hide footbox
            hide empty description
            PUML);

        $this->assertSame(['Outer', 'Inner', 'Elsewhere'], \array_keys($diagram->groups));
        $this->assertSame([null, '<<s>>', 'Outer'], [$diagram->groups['Outer']->color, $diagram->groups['Outer']->stereotype, $diagram->groups['Inner']->parent]);
        $this->assertSame(['Outer', 'Outer', 'Inner', 'Elsewhere', 'Outer', 'Outer', null], \array_map(static fn (LegacyEntity $entity): ?string => $entity->group, \array_values($diagram->entities)));
        $this->assertSame([['multi', 'long', 'line'], '<<x>>', '#yellow'], [$diagram->entities['M']->display, $diagram->entities['M']->stereotype, $diagram->entities['M']->color]);
        $this->assertSame(['more'], $diagram->entities['more']->display);
    }

    public function testAPartitionIsTakenTenWiderEitherSideAsPlantUmlTakesIt(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\npartition P {\n(*) --> \"Climbs\"\n}\n@enduml"));
        $folder = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 'none' !== $mark->stroke))[0];

        // As the jar draws it: the partition from 16 to 108, the diagram 133 by 166.
        $this->assertEqualsWithDelta([16.0, 108.0], [\min(\array_column($folder->points, 0)) / Units::POINT, \max(\array_column($folder->points, 0)) / Units::POINT], 0.01);
        $this->assertEqualsWithDelta([133.0, 166.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT], 1e-9);
    }

    public function testNotesStandBesideTheLastEntityOrOnTheLastLink(): void
    {
        $diagram = self::read(<<<'PUML'
            note right: before anything
            (*) --> "A"
            note left #pink: left
            note top: top
            note bottom
              bottom
            end note
            "A" --> "B"
            note on link: on the link
            note on link
              again
            end note
            PUML);

        $notes = \array_values(\array_filter($diagram->entities, static fn (LegacyEntity $entity): bool => 'note' === $entity->kind));
        $this->assertSame([['before anything'], ['left'], ['top'], ['bottom']], \array_map(static fn (LegacyEntity $note): array => $note->display, $notes));
        $this->assertSame('#pink', $notes[1]->color);
        $this->assertSame(
            [['start', $notes[0]->id, 1], [$notes[1]->id, 'A', 1], [$notes[2]->id, 'A', 2], ['A', $notes[3]->id, 2]],
            \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to, $link->length], self::notesOnly(\array_slice($diagram->links, offset: 0, length: 5))),
        );
        $this->assertSame([[['on the link'], 5], [['again'], 5]], \array_map(static fn ($note): array => [$note->lines, $note->link], $diagram->notes));
        $sideways = self::read("left to right direction\n(*) --> A\nnote right: r\nnote left: l\nnote top: t\nnote bottom: b");
        $this->assertSame(Direction::LeftRight, $sideways->direction);
        $this->assertSame([2, 2, 1, 1], \array_map(static fn (PlantUmlLink $link): int => $link->length, \array_slice($sideways->links, offset: 1)));
    }

    /**
     * @param list<PlantUmlLink> $links
     * @return list<PlantUmlLink>
     */
    private static function notesOnly(array $links): array
    {
        return \array_values(\array_filter($links, static fn (PlantUmlLink $link): bool => $link->dashed));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'else alone' => ["(*) --> A\nelse", 'No if for this else'];
        yield 'endif alone' => ["(*) --> A\nendif", 'No if for this endif'];
        yield 'end partition alone' => ["(*) --> A\nend partition", 'No partition defined'];
        yield 'a link from nothing' => ["--> A\n(*) --> B", 'No such activity'];
        yield 'an if from nothing' => ['if "a" then', 'No if possible at this point'];
        yield 'a long link from nothing' => ["--> \"a\nb\"", 'No such entity'];
        yield 'a note on no link' => ['note on link: x', 'The PlantUML note has no link to stand on: note on link: x'];
    }

    #[DataProvider('refused')]
    public function testWhatPlantUmlFailsOnIsRefused(string $lines, string $message): void
    {
        try {
            self::read($lines);
            $this->fail('Expected a refusal');
        } catch (Unsupported $refusal) {
            $this->assertInstanceOf(PlantUmlRefusal::class, $refusal->getPrevious());
            $this->assertSame($message, $refusal->getPrevious()->getMessage());
        }
    }

    public function testAnActivityHiddenIsLaidOutButNotDrawnNorItsLinks(): void
    {
        $drawn = static fn (string $rule): array => (static fn ($drawing): array => [
            $drawing->width / Units::POINT,
            $drawing->height / Units::POINT,
            \count(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)),
        ])(Work::run(PlantUmlPipeline::native()->drawing("@startuml\n(*) --> \"First\" <<s>>\n\"First\" --> (*)\n{$rule}\n@enduml")));

        // As the jar draws them: by name or stereotype, its place kept; a circle, members or stereotypes to hide, as an unlinked one, none.
        $this->assertEqualsWithDelta(
            [[66.0, 176.0, 0], [66.0, 176.0, 0], [66.0, 176.0, 1], [66.0, 176.0, 1]],
            [$drawn('hide First'), $drawn('hide <<s>>'), $drawn('hide circle'), $drawn('hide @unlinked')],
            1e-9,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupported(): iterable
    {
        yield 'inner activity' => ['(*) --> {', 'The native PlantUML renderer does not draw inner activities'];
        yield 'url' => ["(*) --> A\nA [[http://a]]--> B", 'The native PlantUML renderer does not draw URLs: A [[http://a]]--> B'];
        yield 'a namespace separator' => ["(*) --> A\nset separator none", 'The native PlantUML renderer does not draw: set separator none'];
        yield 'link to a partition' => ["partition P {\n(*) --> A\n}\nA --> P", 'The native PlantUML renderer does not draw links to partitions'];
    }

    #[DataProvider('unsupported')]
    public function testWhatIsNotDrawnNativelyIsLeftToPlantUml(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        self::read($lines);
    }

    public function testTheModelIsTheGraphDrawn(): void
    {
        $model = self::read("title Flow\npartition P {\n(*) --> \"A\" #pink\n}\n--> ===B===\n-->[x] if \"t\" then\n-left-> \"a\" as C\nnote right: n\nendif\n-[hidden]-> (*)")->model();

        $this->assertInstanceOf(Graph::class, $model);
        $this->assertSame('Flow', $model->title);
        $this->assertSame(
            [Shape::Circle, Shape::Rounded, Shape::Box, Shape::Diamond, Shape::Rounded, Shape::Note, Shape::Circle],
            \array_map(static fn ($node): Shape => $node->shape, $model->nodes),
        );
        $this->assertSame([null, 'a'], [$model->nodes[1]->label, $model->nodes[4]->label]);
        $this->assertSame(['P', ['start', 'A']], [$model->clusters[0]->label, $model->clusters[0]->nodes]);
        $this->assertSame('x t', $model->edges[2]->label);
        $this->assertCount(5, $model->edges, 'the hidden link left out');
    }

    /** @return iterable<string, array{string}> */
    public static function drawings(): iterable
    {
        yield 'everything' => [
            "partition P #lightblue {\n(*) --> A\n}\nA --> ===B===\n-->[x] if \"t\" then\n--> C\nnote right: right\nelse\n--> D\nnote left: left\nendif\nC --> E\nnote top: top\nD --> F\nnote bottom: bottom\nE --> G\nnote right: two\nnote right: links\nE --> H\nH -> I\nI -> J\nJ -> K\nnote left: over\nH -> K",
        ];
        yield 'notes above and below' => ["(*) --> A\nnote top: above\nnote bottom: below\nA --> B\nA --> C\nB --> D\nC --> D\nnote left: far"];
        yield 'left to right' => ["left to right direction\nskinparam nodesep 30\nskinparam ranksep 50\n(*) --> ===B===\n--> A\nnote top of link: n\n--> (*)"];
    }

    #[DataProvider('drawings')]
    public function testEveryDiagramDraws(string $lines): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

        $this->assertNotSame([], \array_filter($drawing->marks, static fn (Mark $mark): bool => null !== $mark->text));
    }

    public function testAnArrowTurnedUpPutsItsStartFirstAsSvekWritesIt(): void
    {
        $x = [];
        $source = (string) \file_get_contents(__DIR__.'/../../fixtures/plantuml-parity/legacy/arrows.puml');
        foreach (Work::run(PlantUmlPipeline::native()->drawing($source))->marks as $mark) {
            if (!\in_array($mark->text, ['send', 'store'], strict: true)) {
                continue;
            }
            $x[$mark->text] = $mark->points[0][0];
        }

        // As dot reads the file svek writes, the start of the arrow turned up first: the bar's later branch comes out on its left, as PlantUML draws it.
        $this->assertLessThan($x['send'], $x['store']);
    }

    public function testALinkStopsAtABranchsDiamondAsPlantUmlDrawsIt(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../fixtures/plantuml-parity/legacy/arrows.puml');
        $marks = Work::run(PlantUmlPipeline::native()->drawing($source))->marks;
        $diamonds = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 4 === \count($mark->points) && self::square($mark)));
        $this->assertCount(1, $diamonds);
        [$top, $right] = $diamonds[0]->points;
        [$cx, $cy, $half] = [$top[0], $right[1], $right[0] - $top[0]];

        $on = [];
        foreach ($marks as $mark) {
            if (MarkKind::Polygon !== $mark->kind || $mark === $diamonds[0]) {
                continue;
            }
            foreach ($mark->points as [$x, $y]) {
                // On the outline, within half a point, between its corners: the link from "check input" comes in aslant.
                if (\abs(\abs($x - $cx) + \abs($y - $cy) - $half) >= ($half / 24) || \abs($x - $cx) <= ($half / 12) || \abs($y - $cy) <= ($half / 12)) {
                    continue;
                }
                $on[] = [$x, $y];
            }
        }

        $this->assertNotSame([], $on, 'an arrowhead\'s tip on the diamond');
    }

    /** Whether a polygon of four corners is a square stood on its corner, as a branch is drawn. */
    private static function square(Mark $mark): bool
    {
        [$top, $right, $bottom, $left] = $mark->points;

        return $top[0] === $bottom[0] && $left[1] === $right[1] && \abs($right[0] - $top[0] - ($right[1] - $top[1])) < 1e-6;
    }

    private static function read(string $lines): PlantUmlLegacyActivity
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlLegacyActivity::class, $diagram);

        return $diagram;
    }
}
