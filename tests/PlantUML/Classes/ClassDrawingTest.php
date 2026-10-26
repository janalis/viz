<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Classes;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassEntity;
use Janalis\Viz\PlantUML\Classes\ClassPortions;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class ClassDrawingTest extends TestCase
{
    public function testAGenericStandsInABoxPastTheClasssTopRightCorner(): void
    {
        $entity = new ClassEntity('Box', ['Box']);
        $entity->generic = 'T';
        $box = ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]));
        $generic = \array_values(\array_filter($box->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && $mark->dashed))[0];
        $xs = \array_column($generic->points, 0);
        $ys = \array_column($generic->points, 1);

        // As the jar draws it: its 12-point text and 2, 3 past the class's right and top.
        $this->assertEqualsWithDelta([$box->width + 3 - 9.588, $box->width + 3, -3.0, 13.133], [\min($xs), \max($xs), \min($ys), \max($ys)], 0.01);
    }

    public function testAMembersIconKeepsRoomByTheCircledCharactersSize(): void
    {
        $left = static function (string $skin): float {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$skin}\nclass Order {\n  +id : int\n}\n@enduml"));
            $text = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && 'id : int' === $mark->text))[0];

            return ($text->points[0][0] - (\array_sum($text->slots ?? []) / 2)) / Units::POINT;
        };

        // As the jar sets the field: 20 past the class's left at 17, 23 at 24 and 15 at 2 — hidden or not — and a size not in whole points as 17.
        $this->assertEqualsWithDelta(27.0, $left(''), 0.01);
        $this->assertEqualsWithDelta(30.0, $left('skinparam circledCharacterFontSize 24'), 0.01);
        $this->assertEqualsWithDelta(22.0, $left("skinparam circledCharacterFontSize 2\nhide circle"), 0.01);
        $this->assertEqualsWithDelta(27.0, $left('skinparam circledCharacterFontSize 8.9'), 0.01);
    }

    public function testTypeParametersAreMeasuredOneBiggerAndNotAtAllOnALoneClass(): void
    {
        $size = static function (string $source): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass Box<T> {\n  + get() : T\n}\n{$source}\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sizes them: alone, as without them; linked, 4 wider than without them, and 3 taller where they stand at the top.
        $this->assertEqualsWithDelta([99.0, 84.0], $size(''), 1e-9);
        $this->assertEqualsWithDelta([105.0, 194.0], $size("class Shelf\nShelf --> Box"), 1e-9);
        $this->assertEqualsWithDelta([205.0, 89.0], $size("class Shelf\nShelf -> Box"), 1e-9);
    }

    public function testAnEmptyClassIsAHeaderAndTwoEmptyCompartments(): void
    {
        $box = ClassBox::of(new ClassEntity('Dog', ['Dog']), StyleSheet::skin(), new ClassPortions([]));

        // As PlantUML: a 32-point header around the circled character, two 8-point compartments.
        $this->assertSame([32.0, 48.0], [$box->header, $box->height]);
        $this->assertEqualsWithDelta(26 + PlantUmlMetrics::width('Dog', 14.0) + 6, $box->width, 1e-9);
        $texts = \array_values(\array_filter($box->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $this->assertSame(['C', 'Dog'], \array_map(static fn (Mark $mark): string => $mark->text, $texts));
    }

    public function testHeadersCarryStereotypesTypeParametersAndTheSpotOfTheirKind(): void
    {
        $entity = new ClassEntity('Box', ['Box'], 'interface');
        $entity->stereotypes = ['service'];
        $entity->generic = 'T';
        $box = ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]));

        $texts = \array_values(\array_filter($box->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $this->assertSame(['I', '«service»', 'Box', 'T'], \array_map(static fn (Mark $mark): string => $mark->text, $texts));
        $this->assertGreaterThan(32.0, $box->header);
        // A circle is drawn as PlantUML draws one: the circle, its name below, no spot.
        $other = new ClassEntity('Odd', ['Odd'], 'circle');
        $this->assertSame(
            ['Odd'],
            \array_map(
                static fn (Mark $mark): string => $mark->text,
                \array_values(\array_filter(ClassBox::of($other, StyleSheet::skin(), new ClassPortions([]))->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)),
            ),
        );
    }

    public function testMembersFallIntoCompartmentsWithTheirIcons(): void
    {
        $entity = new ClassEntity('A', ['A']);
        $entity->body = ['- secret : int', '+ run()', '# guard()', '~ local : int', '{static} {method} counter', '{abstract} shape()', 'plain'];
        $marks = ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]))->marks(0.0, 0.0);

        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
        $this->assertSame(['C', 'A', 'secret : int', 'local : int', 'plain', 'run()', 'guard()', 'counter', 'shape()'], $texts);
        $icons = \array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 1.0 === $mark->strokeWidth && \count($mark->points) < 24);
        $this->assertCount(4, $icons, 'one icon per member written with a visibility');
        $this->assertCount(
            1,
            \array_filter(
                $marks,
                static fn (Mark $mark): bool => (
                    MarkKind::Polyline === $mark->kind
                    && \count($mark->points) === 2
                    && 0.5 === $mark->strokeWidth
                    && $mark->points[0][1] === $mark->points[1][1]
                    && $mark->points[0][0] > 1.0
                ),
            ),
            'a static member underlined',
        );
        $plain = ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]), iconSize: 0.0);
        $this->assertLessThan(ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]))->width, $plain->width);
    }

    public function testSeparatorsKeepTheBodyInOrder(): void
    {
        $entity = new ClassEntity('A', ['A']);
        $entity->body = ['first', '.. dotted ..', 'second', '==', 'third', '__ thick __', 'fourth', '-- plain --'];
        $marks = ClassBox::of($entity, StyleSheet::skin(), new ClassPortions([]))->marks(0.0, 0.0);

        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
        $this->assertSame(['C', 'A', 'first', 'dotted', 'second', 'third', 'thick', 'fourth', 'plain'], $texts);
        $this->assertNotEmpty(\array_filter($marks, static fn (Mark $mark): bool => [1.0, 2.0] === $mark->dashes));
    }

    public function testHideAndShowTakeOutPartsAndClassifiers(): void
    {
        $entity = new ClassEntity('A', ['A'], 'enum');
        $entity->stereotypes = ['x'];
        $portions = new ClassPortions([
            [false, null,    true,  'member'],
            [false, 'enum',  false, 'circle'],
            [false, '<<x>>', false, 'stereotype'],
            [true,  'A',     false, 'method'],
            [false, '<<y>>', false, 'field'],
            [false, 'A',     false, 'entity'],
        ]);

        $box = ClassBox::of($entity, StyleSheet::skin(), $portions);
        $this->assertSame(8.0, $box->height - $box->header, 'only the shown, empty methods compartment');
        $this->assertSame(PlantUmlText::lineHeight(14.0) + 10, $box->header, 'no circle, no stereotype');
        $this->assertFalse($portions->shown($entity, 'circle'));
        $this->assertTrue($portions->shown($entity, 'field', 3));
        $this->assertFalse($portions->shown($entity, 'field', 0));
        $this->assertTrue($portions->hidden($entity, linked: true));
        // As PlantUML matches a name: its qualified name, quotes and all, `*` standing for any characters.
        $this->assertFalse(new ClassPortions([[false, '"A"', false, 'entity']])->hidden($entity, linked: true));
        $this->assertTrue(new ClassPortions([[false, '*', false, 'removed']])->hidden($entity, linked: true, kind: 'removed'));
        $this->assertFalse(new ClassPortions([[false, 'B*', false, 'entity']])->hidden($entity, linked: true));
        $this->assertTrue(new ClassPortions([[false, '@unlinked', false, 'entity']])->hidden($entity, linked: false));
        $this->assertFalse(new ClassPortions([
            [false, '@unlinked', false, 'entity'],
            [false, 'B',         false, 'member'],
        ])->hidden($entity, linked: true));
    }

    public function testAStereotypeIsSetAt12PointsAndTheCircleSizedByItsLetterAsPlantUmlSetsThem(): void
    {
        $entity = new ClassEntity('A', ['Repository']);
        $entity->stereotypes = ['service'];

        // As the jar sets it: «service» at 12 points over the name at 14, 10 around them, whatever the styles' font size.
        $styled = StyleSheet::skin()->with("classDiagram {\n class {\n  stereotype {\n   FontSize 20\n  }\n }\n}");
        $this->assertEqualsWithDelta(40.621, ClassBox::of($entity, $styled, new ClassPortions([]))->header, 1e-3);

        // The circle a third of its letter's size and 17 across each way, to the nearest point: 10 for a 12-point letter, 11 for the default 17.
        $plain = new ClassEntity('B', ['B']);
        $this->assertSame(30.0, ClassBox::of($plain, StyleSheet::skin(), new ClassPortions([]), letter: 12.0)->header);
        $this->assertSame(32.0, ClassBox::of($plain, StyleSheet::skin(), new ClassPortions([]))->header);
    }

    public function testAPackagesTitleAndClassesStandWherePlantUmlDrawsThem(): void
    {
        $baselines = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\npackage P {\n class A\n}\n@enduml"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $baselines[$mark->text] = \round($mark->points[0][1] / Units::POINT, precision: 3);
        }

        // As the jar draws them: the title 2 below the package's top, the package taken as the path it draws.
        $this->assertSame([21.535, 62.291], [$baselines['P'], $baselines['A']]);
    }

    public function testTheDiagramIsAsBigAsPlantUmlMakesIt(): void
    {
        $height = static fn (string $lines): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"))->height / Units::POINT;

        // As the jar sizes them: a lone class, laid out without dot, 2 less room past it than two.
        $this->assertSame([68.0, 70.0, 84.0], [$height('class A'), $height("class A\nclass B"), $height("class A {\nx\n}")]);
        $this->assertSame([56.0, 57.0], [$height('rectangle A'), $height("rectangle A\nrectangle B")]);
    }

    public function testALinksEndIsTakenAsPlantUmlsLimitFinderTakesTheShapeItIsDrawnAs(): void
    {
        $width = static fn (string $link): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\nhide circle\nclass X\nclass Y\nX {$link} Y\n@enduml"))->width / Units::POINT;

        // As the jar sizes them: an arrow, a diamond or a triangle drawn as a polygon, 10 wider either side; a parenthesis as its whole circle; lines as they run.
        $this->assertSame([49.0, 49.0, 53.0, 47.0, 38.0, 36.0, 36.0], \array_map($width, ['-->', '--*', '--|>', '-->>', '--(', '--||', '--x']));
    }

    public function testAStereotypesSpotCirclesItsOwnCharacterInItsOwnColour(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A <<Entity>>\nclass B <<(S,#FF7700) Singleton>>\nA --> B\n@enduml"));
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));

        // As the jar draws them: the spot's character in its colour, its label alone the stereotype, 1 wider either side than its text.
        $this->assertSame(['C', '«Entity»', 'A', 'S', '«Singleton»', 'B'], $texts);
        $this->assertContains('#ff7700', \array_map(static fn (Mark $mark): ?string => $mark->fill, $drawing->marks));
        $this->assertSame([116.0, 195.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertSame(['Singleton'], ClassReader::stereotypes('<<(S,#FF7700) Singleton>>'));
        $this->assertSame(['a', 'b'], ClassReader::stereotypes('<<(X) a, b>>'));
    }

    public function testSeparatorsAreDrawnAsPlantUmlsHorizontalLinesDrawThem(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A {\n  a\n  --\n  b\n  ..\n  c\n  ==\n  d\n  __ title __\n  e\n}\n@enduml"));
        $rules = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Polyline !== $mark->kind) {
                continue;
            }
            $rules[] = [
                \round($mark->points[0][0] / Units::POINT, precision: 3),
                \round($mark->points[0][1] / Units::POINT, precision: 3),
                \round($mark->points[1][0] / Units::POINT, precision: 3),
                $mark->dashed,
            ];
        }
        $title = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => 'title' === $mark->text))[0];

        // As the jar draws them, 1 in from the sides: `==` twice, 2 apart; a titled line half its title's height lower, either side of its title.
        $this->assertSame(
            [
                [8.0,    39.0,    47.659, false],
                [8.0,    63.488,  47.659, false],
                [8.0,    87.977,  47.659, true],
                [8.0,    112.465, 47.659, false],
                [8.0,    114.465, 47.659, false],
                [8.0,    145.197, 14.646, false],
                [41.013, 145.197, 47.659, false],
            ],
            $rules,
        );
        $this->assertEqualsWithDelta(149.988, $title->points[0][1] / Units::POINT, 0.001);
        $this->assertSame([61.0, 186.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testANoteOnALinkStandsInTheBoxSvekWritesForIt(): void
    {
        $at = static function (string $position, string $label): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A\nclass B\nA --> B{$label}\nnote {$position} on link\n  a note\nend note\n@enduml"));
            $note = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#feffdd' === $mark->fill))[0];

            return [
                \round($drawing->width / Units::POINT, precision: 2),
                \round($drawing->height / Units::POINT, precision: 2),
                \round(\min(\array_column($note->points, 0)) / Units::POINT, precision: 2),
                \round(\min(\array_column($note->points, 1)) / Units::POINT, precision: 2),
            ];
        };

        // As the jar draws them: its lines without the columns they share, svek's room around the label's text alone, the note taken as the path it is drawn as.
        $this->assertSame([107.0, 213.0, 32.83, 90.0], $at('bottom', label: ''));
        $this->assertSame([107.0, 230.0, 32.83, 107.31], $at('bottom', label: ' : lbl'));
        $this->assertSame([125.0, 213.0, 50.53, 90.0], $at('right', label: ' : lbl'));
        $this->assertSame([107.0, 230.0, 32.83, 90.0], $at('top', label: ' : lbl'));
    }

    public function testALoneNoteIsDrawnSevenInAsPlantUmlDrawsIt(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nnote \"hello\" as N\n@enduml"));
        $corner = $drawing->marks[0]->points[0];

        // As the jar draws it: its box 7 in from its sides.
        $this->assertSame([71.0, 45.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertEqualsWithDelta([7.0, 7.0], [$corner[0] / Units::POINT, $corner[1] / Units::POINT], 1e-9);
    }

    public function testACircleAndADiamondStandAsPlantUmlDrawsThem(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\ndiamond D\nclass A\nclass B\nA -- D\nD -- B\n@enduml"));

        // As the jar draws them: a nameless diamond 24 across, between its classes.
        $this->assertSame(262.0, $drawing->height / Units::POINT);
        $baseline = 0.0;
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A\ncircle X\nA -- X\n@enduml"))->marks as $mark) {
            $baseline = 'X' === $mark->text ? $mark->points[0][1] / Units::POINT : $baseline;
        }
        // A circle's node as dot sizes the table svek writes for it, its name 17 below its centre.
        $this->assertEqualsWithDelta(174.535, $baseline, 0.005);
    }

    public function testAPointedLabelStandsAsPlantUmlDrawsIt(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass Author\nclass Book\nAuthor \"1..*\" -- \"0..*\" Book : writes >\n@enduml"));
        $text = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => 'writes' === $mark->text))[0];
        $triangle = \array_values(\array_filter(
            $drawing->marks,
            static fn (Mark $mark): bool => (
                MarkKind::Polygon === $mark->kind
                && 3 === \count(\array_unique(\array_map(static fn (array $point): string => \implode(',', $point), $mark->points), \SORT_STRING))
            ),
        ))[0];
        $left = ($text->points[0][0] / Units::POINT) - (PlantUmlMetrics::width('writes', 13.0) / 2);
        $xs = \array_map(static fn (array $point): float => $point[0] / Units::POINT, $triangle->points);

        // As the jar draws it: its text 13 in from where its room starts, its triangle centred 4 in, 114 wide in all.
        $this->assertEqualsWithDelta(60.3, $left, 0.01);
        $this->assertEqualsWithDelta(51.3, (\min($xs) + \max($xs)) / 2, 0.01);
        $this->assertSame(114.0, $drawing->width / Units::POINT);
    }

    public function testEndLabelsStandWhereDotSetsThem(): void
    {
        $baselines = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\nleft to right direction\nclass Author\nclass Book\nAuthor \"1..*\" -- \"0..*\" Book\n@enduml"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind || !\str_contains($mark->text, '..')) {
                continue;
            }
            $baselines[$mark->text] = $mark->points[0][1] / Units::POINT;
        }

        // As the jar draws them, from dot's head and tail labels: laid out left to right, both below the line, whatever its slope.
        $this->assertEqualsWithDelta(['1..*' => 44.693, '0..*' => 45.028], $baselines, 0.5);
    }

    public function testColourSpecsReadTheirParts(): void
    {
        $this->assertSame('#ffc0cb', ClassColors::back('#pink'));
        $this->assertSame('#ff0000', ClassColors::line('#back:pink;line:red;text:blue'));
        $this->assertSame('#0000ff', ClassColors::text('#back:pink;line:red;text:blue'));
        $this->assertNull(ClassColors::line('#pink'));
        $this->assertNull(ClassColors::back(null));
    }

    public function testWholeDiagramsDrawPackagesLinksLabelsAndNotes(): void
    {
        $drawing = self::draw(<<<'PUML'
            skinparam nodesep 50
            skinparam ranksep 80
            package zoo <<cloud>> #lightblue {
              class Keeper
              together {
                class Mate
              }
            }
            class Animal
            class Dog #back:pink;line:red
            Animal <|-- Dog
            Dog "1" *-- "many" Leg : has >
            Dog -up-> Ball : < plays
            Keeper --> Animal : feeds
            note on link : daily
            Dog -[#blue,dashed,thickness=2]-> Bowl
            Dog -[dotted]- Bone
            Dog -[hidden]- Cage
            Dog ---> Ghost
            hide Ghost
            note left of Dog : good boy
            note bottom of Animal : base
            note "free" as Free
            title Zoo
            PUML);

        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
        foreach (['Zoo', 'zoo', 'has', '1', 'many', 'plays', 'feeds', 'daily', 'good boy', 'base', 'free', 'Keeper', 'Mate'] as $text) {
            $this->assertContains($text, $texts);
        }
        $this->assertNotContains('Ghost', $texts);
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#ffc0cb' === $mark->fill), 'the coloured class');
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#add8e6' === $mark->fill), 'the coloured package');
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#0000ff' === $mark->stroke && [7.0 * Units::POINT, 7.0 * Units::POINT] === $mark->dashes));
        $this->assertSame('#ffffff', $drawing->background);
    }

    public function testAHeadLabelThatWouldOverlapTheTailLabelStandsOnTheOtherSide(): void
    {
        $centre = static function (Drawing $drawing, string $text, float $side = 0.0): float {
            foreach ($drawing->marks as $mark) {
                if ($text === $mark->text) {
                    return $mark->points[0][0] + (($side * \array_sum($mark->slots ?? [])) / 2);
                }
            }
            self::fail('No '.$text);
        };
        // Labels short enough leave both ends on the line's left, as dot sets them: their right sides by it, a point apart at most, as the jar sets them.
        $short = self::draw('A "t" --> "h" B');
        $this->assertEqualsWithDelta($centre($short, text: 't', side: 1.0), $centre($short, text: 'h', side: 1.0), Units::POINT);
        // Labels tall enough to meet put the head's on the right.
        $tall = self::draw("A \"t\\n1\\n2\\n3\" --> \"h\\n1\\n2\\n3\" B");
        $this->assertGreaterThan($centre($tall, text: 't'), $centre($tall, text: 'h'));
    }

    public function testCustomIconSizesAndBackgroundsAreHonoured(): void
    {
        $drawing = self::draw("skinparam classAttributeIconSize 0\nskinparam backgroundColor transparent\nclass A {\n  - x\n}\nA --> B");

        $this->assertNull($drawing->background);
        // As PlantUML writes a visibility with no icons: before the member, the space after it dropped.
        $this->assertContains('-x', \array_map(static fn (Mark $mark): string => $mark->text, $drawing->marks));
    }

    public function testNamespacesEmptyPackagesAndHiddenClassesStandAsPlantUmlSetsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: a path's packages made, each named by its last part.
        $this->assertSame([196.0, 252.0], $size("class a.b.C\nclass a.D\nC2 --> a.b.C"));
        $this->assertSame([187.0, 310.0], $size("namespace net.dummy {\n class Person\n}\nnamespace net.foo {\n class Meeting\n}\nnet.dummy.Person --> net.foo.Meeting"));
        $this->assertSame([143.0, 287.0], $size("set separator ::\nclass A::B::C {\n +f()\n}\nA::B::C <|-- A::D"));
        // A package holding nothing is a node, its title 10 inside its sides.
        $this->assertSame([126.0, 73.0], $size("package q {\n}\nclass X"));
        $this->assertSame([141.0, 230.0], $size("package a {\n class X\n}\npackage q {\n class a.Y\n}\nX --> Y"));
        // Hidden, a class keeps its place and its room, drawn with nothing that touches it; removed, it is gone.
        $hidden = self::draw("class A\nclass B\nclass Lone\nA -- B\nhide @unlinked");
        $this->assertSame([163.0, 178.0], [$hidden->width / Units::POINT, $hidden->height / Units::POINT]);
        $this->assertSame(
            ['C', 'A', 'C', 'B'],
            \array_values(\array_map(static fn (Mark $mark): string => $mark->text, \array_filter($hidden->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind))),
        );
        $this->assertSame([63.0, 178.0], $size("class B\nclass C\nB -- C\nhide C"));
        $this->assertSame([69.0, 186.0], $size("class A\nclass B <<S>>\nA -- B\nhide <<S>>"));
        $this->assertSame([63.0, 70.0], $size("class A\nclass B\nA -- B\nremove B"));
    }

    public function testQualifiersStandWhereTheirLinksLeaveAsPlantUmlDrawsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };
        $boxes = static fn (Drawing $drawing): array => \array_values(\array_map(
            static fn (Mark $mark): array => [
                \round($mark->points[0][0] / Units::POINT, precision: 2),
                \round($mark->points[0][1] / Units::POINT, precision: 2),
                \round($mark->points[2][1] / Units::POINT, precision: 2),
            ],
            \array_filter(
                $drawing->marks,
                static fn (Mark $mark): bool => (
                    MarkKind::Polygon === $mark->kind
                    && (0.5 * Units::POINT) === $mark->strokeWidth
                    && 4 === \count($mark->points)
                    && ($mark->points[2][1] - $mark->points[0][1]) < (20 * Units::POINT)
                ),
            ),
        ));

        // As the jar sets them: svek keeps the qualifier's room in a table about the class, as dot sizes it.
        $this->assertSame([63.0, 200.0], $size("class A\nclass B\nA [key] --> B"));
        $this->assertSame([73.0, 217.0], $size("class A\nclass B\nA [key] --> \"1\" B : uses"));
        $this->assertSame([63.0, 200.0], $size("class A\nclass B\nA --> [id] B"));
        $this->assertSame([158.0, 70.0], $size("class A\nclass B\nA [k] - B"));
        $this->assertSame([158.0, 70.0], $size("class A\nclass B\nA - [k] B"));

        // Its box where the line leaves the class, its end's mark pushed past it.
        $composed = self::draw("class A\nclass B\nA [key] *-- B");
        $this->assertSame([[13.85, 55.0, 73.49]], $boxes($composed));
        $diamond = \array_values(\array_filter($composed->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 4 === \count($mark->points) && '#181818' === $mark->fill));
        $this->assertEqualsWithDelta(73.49, \min(\array_column($diamond[0]->points, 1)) / Units::POINT, 0.01);

        // Two below one class pushed apart, as PlantUML pushes them: the jar sets them at 45.928 and 68.514.
        $this->assertSame([[45.93, 55.0, 73.49], [68.52, 55.0, 73.49]], $boxes(self::draw("class A\nclass B\nclass C\nA [x] --> B\nA [yy] --> C")));
    }

    public function testLinksToPackagesRectanglePackagesHiddenLinksAndWrittenVisibilityStandAsPlantUmlSetsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: a link to a package laid out to a point inside it, cut at its frame.
        $this->assertSame([140.0, 209.0], $size("class A\npackage P {\n class B\n}\nA --> P"));
        $this->assertSame([136.0, 291.0], $size("package P {\n class B\n}\npackage Q {\n class C\n}\nP ..> Q"));
        $this->assertSame([63.0, 182.0], $size("class A\npackage P {\n}\nA --> P"));
        // A package marked a rectangle drawn as one, its title centred.
        $rectangle = self::draw("class A\npackage P <<Rectangle>> {\n class B\n}\nA --> P");
        $this->assertSame([141.0, 208.0], [$rectangle->width / Units::POINT, $rectangle->height / Units::POINT]);
        // A hidden link laid out and measured, not drawn.
        $this->assertSame([74.0, 178.0], $size("class A\nclass B\nA -[hidden]-> B\nA -[#red,bold]-> B"));
        // With no icons, a member's visibility written as text.
        $written = self::draw("skinparam classAttributeIconSize 0\nclass A {\n -x : int\n +f()\n}");
        $this->assertSame([79.0, 100.0], [$written->width / Units::POINT, $written->height / Units::POINT]);
        $this->assertContains('-x : int', \array_map(static fn (Mark $mark): string => $mark->text, $written->marks));
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }
}
