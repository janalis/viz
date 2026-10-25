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
                    && $mark->points[0][0] > 0.0
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
            [false, '"A"',   false, 'entity'],
        ]);

        $box = ClassBox::of($entity, StyleSheet::skin(), $portions);
        $this->assertSame(8.0, $box->height - $box->header, 'only the shown, empty methods compartment');
        $this->assertSame(PlantUmlText::lineHeight(14.0) + 10, $box->header, 'no circle, no stereotype');
        $this->assertFalse($portions->shown($entity, 'circle'));
        $this->assertTrue($portions->shown($entity, 'field', 3));
        $this->assertFalse($portions->shown($entity, 'field', 0));
        $this->assertTrue($portions->hidden($entity, linked: true));
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
        $this->assertContains('x', \array_map(static fn (Mark $mark): string => $mark->text, $drawing->marks));
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }
}
