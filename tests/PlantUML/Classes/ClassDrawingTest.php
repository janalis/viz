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
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class ClassDrawingTest extends TestCase
{
    public function testAFlatLabelledLinkKeepsItsLabelsWidthBetweenItsEnds(): void
    {
        $size = static fn (string $link): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$link}\n@enduml")),
        );

        // As the jar sets them: its ends no two separations apart, but the label's width and one.
        $this->assertSame([[146.0, 70.0], [147.0, 70.0]], [$size('A - B : x'), $size('0-7: "A"')]);
    }

    public function testABigCircledCharacterIsMeasuredInsideItsCircle(): void
    {
        $size = static fn (string $skin): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$skin}\nclass Order {\n  +id : int\n}\n@enduml")),
        );

        // As the jar sizes them: its letter a path inside its circle, not a line of text reaching above the box.
        $this->assertSame([[100.0, 94.0], [96.0, 90.0]], [$size('skinparam circledCharacterFontSize 30'), $size('skinparam circledCharacterFontSize 24')]);
    }

    public function testAMapsRowLinksAsPlantUmlDrawsAPortsLink(): void
    {
        $drawing = static fn (string $source): Drawing => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"));
        $size = static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        $linked = $drawing("object London\nmap CapitalCity {\n UK *-> London\n USA => Washington\n}");
        $line = \array_values(\array_filter(
            $linked->marks,
            static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && $mark->strokeWidth >= Units::POINT && $mark->points[0][0] !== $mark->points[\count($mark->points) - 1][0],
        ))[0];

        // As the jar draws them: the key alone across the map, its link from its row; a port's link from its row.
        $this->assertSame([[254.0, 83.0], [100.0, 161.0]], [$size($linked), $size($drawing("map M {\n  key => value\n}\nobject O\nM::key --> O"))]);
        $this->assertEqualsWithDelta(37.7, $line->points[0][1] / Units::POINT, 0.1);
        // A row none so named, the link from the map; a link read the other way, from its row too.
        $this->assertSame([[100.0, 161.0], [64.0, 174.0]], [
            $size($drawing("map M {\n  key => value\n}\nobject O\nM::none --> O")),
            $size($drawing("map M {\n  k => v\n}\nclass O\nM::k <-- O")),
        ]);
        // As dot curves a link out of a row but the last to an element below, left to PlantUML.
        try {
            $drawing("map M {\n  a => b\n  key => value\n}\nobject O\nM::key --> O");
            $this->fail('a row but the last');
        } catch (Unsupported $refused) {
            $this->assertStringContainsString('out of its side', $refused->getMessage());
        }
        try {
            $drawing("class X\nmap M {\n UK *-> London\n}");
            $this->fail('no such entity');
        } catch (\Throwable $refused) {
            $this->assertStringContainsString('No such entity London', $refused->getMessage());
        }
    }

    public function testAMapKeepsAKeyOnceAndANoteStandsWhereItWasWritten(): void
    {
        $drawing = static fn (string $source): Drawing => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"));
        $texts = static fn (Drawing $drawing): array => \array_values(\array_map(
            static fn (Mark $mark): string => $mark->text,
            \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind),
        ));
        $size = static fn (Drawing $drawing): array => [\round($drawing->width / Units::POINT, precision: 6), \round($drawing->height / Units::POINT, precision: 6)];

        // As the jar draws them: a key written again in its first row, with its last value; a floating note before the object written after it.
        $this->assertSame(['M', 'a', '3', 'b', '2'], $texts($drawing("map M {\n  a => 1\n  b => 2\n  a => 3\n}")));
        $this->assertSame([[108.0, 57.0], [125.0, 70.0]], [$size($drawing("note \"a\" as N0\nobject O")), $size($drawing("note \"a\" as N0\nclass C"))]);
        // Two maps side by side, each a table dot sizes in whole points: 141 wide, not a hair less, its half rounded up.
        $this->assertSame([370.0, 83.0], $size($drawing("map M0 {\n  a long key => v\n  key => a long value\n}\nmap M1 {\n  a long key => value\n}")));
    }

    public function testAClassBodyIsSizedAsPlantUmlSizesIt(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [
            \round($drawing->width / Units::POINT, precision: 6),
            \round($drawing->height / Units::POINT, precision: 6),
        ])(Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")));

        // As the jar sizes them: a titled line with nothing under it its title's height and a half; icons' room only where a member of the compartment has one;
        // a diamond's or a triangle's icon a polygon its limit finder takes 10 wider either side, unless the class is laid out alone.
        $this->assertSame([[61.0, 84.0], [61.0, 109.0], [135.0, 100.0], [183.0, 86.0], [93.0, 84.0]], [
            $size("class A {\n  -- dash --\n}"),
            $size("class A {\n  == S ==\n  == S ==\n}"),
            $size("annotation A {\n  +id : int\n  method() : void\n}"),
            $size("class A0 {\n  #count()\n}\nclass A1"),
            $size("class A0 {\n  #count()\n}"),
        ]);
    }

    public function testAnObjectsFieldsReachItsFootForTheLimitFinder(): void
    {
        $height = static fn (string $source): float => \round(Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"))->height / Units::POINT, precision: 6);

        // As the jar sizes them: an object with fields, the tallest, as tall as its box to its foot.
        $this->assertSame([66.0, 83.0], [$height("object O {\n f = 1\n}\nmap M {\n a => b\n}"), $height("object O {\n f = 1\n f = 2\n}\nclass Z")]);
    }

    public function testAHalfLollipopIsHalfItsCircleOpenTowardsItsLine(): void
    {
        $arc = static function (string $source): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A\n{$source}\n@enduml"));
            $arcs = \array_values(\array_filter(
                $drawing->marks,
                static fn (Mark $mark): bool => (
                    MarkKind::Polyline === $mark->kind
                    && 13 === \count($mark->points)
                    && 10.0 === \round(\hypot($mark->points[12][0] - $mark->points[0][0], $mark->points[12][1] - $mark->points[0][1]) / Units::POINT, precision: 2)
                ),
            ));
            $point = static fn (array $at): array => [\round($at[0] / Units::POINT, precision: 2), \round($at[1] / Units::POINT, precision: 2)];

            return [\round($drawing->height / Units::POINT, precision: 6), $point($arcs[0]->points[0]), $point($arcs[0]->points[6]), $point($arcs[0]->points[12])];
        };

        // As the jar draws them: half a circle 10 across, its middle towards the line, measured as the whole circle.
        $this->assertSame([155.0, [22.83, 120.0], [27.83, 115.0], [32.83, 120.0]], $arc('A --(( I'));
        $this->assertSame([139.0, [32.83, 11.0], [27.83, 16.0], [22.83, 11.0]], $arc('I ))-- A'));
    }

    public function testACircleInALinksMiddleTakesItsArcsAsPlantUmlDrawsThem(): void
    {
        $arcs = static fn (string $link): array => \array_values(\array_map(
            static fn (Mark $mark): array => [\round($mark->points[0][0] / Units::POINT, precision: 2), \round($mark->points[0][1] / Units::POINT, precision: 2)],
            \array_filter(
                Work::run(PlantUmlPipeline::native()->drawing("@startuml\n[A] {$link} [B]\n@enduml"))->marks,
                static fn (Mark $mark): bool => (
                    MarkKind::Polyline === $mark->kind
                    && 13 === \count($mark->points)
                    && 14.14 === \round(\hypot($mark->points[12][0] - $mark->points[0][0], $mark->points[12][1] - $mark->points[0][1]) / Units::POINT, precision: 2)
                ),
            ),
        ));

        // As the jar draws them: a quarter turn 10 across about the line's way, on its head's side, its tail's, or both.
        $this->assertSame([[38.9, 90.56]], $arcs('-0)-'));
        $this->assertSame([[24.76, 76.42]], $arcs('-(0-'));
        $this->assertSame([[38.9, 90.56], [24.76, 76.42]], $arcs('-(0)-'));
        $this->assertSame([], $arcs('-0-'));
    }

    public function testAJsonEntityIsDrawnAsPlantUmlDrawsItsData(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [
            \round($drawing->width / Units::POINT, precision: 6),
            \round($drawing->height / Units::POINT, precision: 6),
        ])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")),
        );

        // As the jar sizes them: names beside values, arrays' items one under another, values as written; its stereotype above its name; 13 tall empty.
        $this->assertSame([[57.0, 101.0], [87.0, 75.0], [38.0, 53.0]], [
            $size("json J {\n  \"a\": 1,\n  \"b\": [1, 2]\n}"),
            $size("json \"My data\" as J <<st>> #pink {\n  \"a\": 1\n}"),
            $size("json J {\n}"),
        ]);
        $this->assertSame([[124.0, 163.0], [38.0, 81.0]], [
            $size("json J {\n  \"name\": \"Bob\",\n  \"ok\": true,\n  \"none\": null,\n  \"deep\": {\"x\": 1, \"y\": [true, {\"z\": \"w\"}]}\n}"),
            $size("json J {\n  [\"a\", \"b\"]\n}"),
        ]);
        // A table smaller than dot's least node, 54 across, centred in it, beside a class.
        $this->assertSame([[143.0, 70.0], [143.0, 70.0]], [$size("json J {\n \"a\": 1\n}\nclass A"), $size("map M {\n a => 1\n}\nclass A")]);
        $this->expectExceptionMessage('JSON already exists: J');
        $size("class X\njson J {\n}\njson J {\n}");
    }

    public function testALayoutNewLineChangesNothing(): void
    {
        $size = static fn (string $between): array => (static fn (Drawing $drawing): array => [$drawing->width / Units::POINT, $drawing->height / Units::POINT])(
            Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A\nclass B\n{$between}class C\nclass D\n@enduml")),
        );

        // As the jar draws it: as without it, 141 by 178.
        $this->assertSame([[141.0, 178.0], [141.0, 178.0]], [$size(''), $size("layout_new_line\n")]);
    }

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

    public function testAClassLinkedToItselfIsLaidOutItsLabelsOnTheLoopsOuterSide(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: laid out by dot, not alone, the loop on its right; or above it laid out left to right, its labels left of its ends.
        $this->assertSame([98.0, 70.0], $size("class A\nA --> A"));
        $this->assertSame([156.0, 70.0], $size("class A\nclass B\nA --> A"));
        $this->assertSame([64.0, 104.0], $size("left to right direction\nclass A\nA \"1\" --> \"2\" A"));
        // Its tail label right of the loop's start and above it, moved off the box.
        $drawing = self::draw("class A\nA \"1\" --> A");
        $this->assertSame([98.0, 73.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $label = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => '1' === $mark->text))[0];
        $this->assertEqualsWithDelta(19.811 * Units::POINT, $label->points[0][1], 0.1 * Units::POINT);
    }

    public function testANoteWithASingleLinkPointsAtWhatItStandsBesideAsSvekDrawsItsOpale(): void
    {
        $dashed = static fn (Drawing $drawing): int => \count(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && $mark->dashed));
        $pointing = static fn (Drawing $drawing): int => \count(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 8 === \count($mark->points)));

        // As the jar draws them: beside a class, or linked to one alone, its outline reaching out to it, no line drawn.
        foreach (["class A\nclass B\nA --> B\nnote right of A : a note", "class A\nnote \"x\" as N\nN .. A"] as $lines) {
            $drawing = self::draw($lines);
            $this->assertSame([0, 1], [$dashed($drawing), $pointing($drawing)], $lines);
        }
        // Linked to two classes, its lines drawn.
        $this->assertSame(2, $dashed(self::draw("class A\nclass B\nnote \"x\" as N\nN .. A\nN .. B")));
        // Its link routed over another note, its line drawn as dot draws it: the diagram as tall as the jar makes it.
        $drawing = self::draw("class Bee\nnote right of Bee : a note\nnote right of Bee : a longer note");
        $this->assertSame([1, 1], [$dashed($drawing), $pointing($drawing)]);
        $this->assertSame([313.0, 115.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testANotesSideTurnsWithTheDirectionAsItStoodWhenTheNoteWasRead(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar lays them out: read before the direction, right of the class on its rank, below it laid out left to right; read after, turned below it, a rank to its right.
        $this->assertSame([63.0, 130.0], $size("class A\nnote right of A : x\nleft to right direction"));
        $this->assertSame([152.0, 70.0], $size("left to right direction\nclass A\nnote right of A : x"));
    }

    public function testASelfLinksLabelKeepsItsRoomAboveAndBelowAndPointsAlongTheLoopsStart(): void
    {
        // As the jar bounds it: the 6 points svek leaves round a self-link's label above and below it too.
        $drawing = self::draw("Bee -- Bee : has\nnote on link: two\\nlines");
        $this->assertSame([167.0, 93.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);

        // As svek points it: along the loop's start, leaving the class rightward, turned back for `<`: up.
        $arrows = \array_values(\array_filter(self::draw('Bee -- Bee : < has')->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 3 === \count($mark->points)));
        $this->assertCount(1, $arrows);
        [$tip, $wing, $other] = $arrows[0]->points;
        $this->assertLessThan(\min($wing[1], $other[1]) - (8 * Units::POINT), $tip[1]);
        // Its middle 5 in from the label's left, outside the 6 svek leaves round the text: as far before the text as the jar sets it.
        $text = \array_values(\array_filter(self::draw('Bee -- Bee : < has')->marks, static fn (Mark $mark): bool => 'has' === $mark->text))[0];
        $this->assertEqualsWithDelta(116.65 - 99.258, ($text->points[0][0] - (\array_sum($text->slots ?? []) / 2) - \min(\array_column($arrows[0]->points, 0))) / Units::POINT, 0.05);
    }

    public function testLinksAlongARankSetTheirEndLabelsAsDotDoesAndKeepTheirPairAcross(): void
    {
        $left = static function (Drawing $drawing, string $text): array {
            foreach ($drawing->marks as $mark) {
                if ($text === $mark->text) {
                    return [\round(($mark->points[0][0] - (\array_sum($mark->slots ?? []) / 2)) / Units::POINT, precision: 1), \round($mark->points[0][1] / Units::POINT, precision: 1)];
                }
            }
            self::fail('No '.$text);
        };

        // As the jar sets them, to a quarter of a point: above the line, the tail's right of its start, the head's left of its end; laid out left to right, left of it, the tail's below its start, the head's above its end.
        $drawing = self::draw("class A\nclass B\nA \"t\" - \"h\" B");
        $this->assertEqualsWithDelta([[56.7, 26.1], [67.7, 26.1]], [$left($drawing, text: 't'), $left($drawing, text: 'h')], 0.25);
        $drawing = self::draw("left to right direction\nclass A\nclass B\nA \"t\" - \"h\" B");
        $this->assertEqualsWithDelta([[23.4, 75.7], [18.8, 79.1]], [$left($drawing, text: 't'), $left($drawing, text: 'h')], 0.25);
        // The tail's below the line where its label stands in the way above.
        $drawing = self::draw("class A {\n}\nA \"0..*\" - Bee : < has");
        $this->assertEqualsWithDelta([56.6, 45.3], $left($drawing, text: '0..*'), 0.25);
        // Laid out left to right, a label's arrow is a polygon PlantUML's limit finder takes 10 wider either side; as the jar sizes it.
        foreach (['has' => 86.0, 'haaaaaaaaaaas' => 137.0] as $label => $width) {
            $drawing = self::draw("class A {\n}\nA - Bee : < ".$label."\nleft to right direction");
            $this->assertSame([$width, 170.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        }
        // A link down between two classes a link across joins is across too, as PlantUML's `ClassDiagram` settles it.
        $drawing = self::draw("class Bee\nclass A\nBee \"1\" --> A\nBee -> A");
        $this->assertSame([154.0, 70.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
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
        // Labels short enough leave both ends on the line's left, as dot sets them: each from the corner of the table svek writes, its text's width cut down to whole points, the tail's then moved off its node; where the jar sets them.
        $short = self::draw('A "t" --> "h" B');
        $this->assertEqualsWithDelta(23.448 * Units::POINT, $centre($short, text: 't', side: -1.0), 0.1 * Units::POINT);
        $this->assertEqualsWithDelta(18.815 * Units::POINT, $centre($short, text: 'h', side: -1.0), 0.1 * Units::POINT);
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
        $this->assertSame([73.0, 178.0], $size("class A\nclass B\nA -[hidden]-> B\nA -[#red,bold]-> B"));
        // With no icons, a member's visibility written as text.
        $written = self::draw("skinparam classAttributeIconSize 0\nclass A {\n -x : int\n +f()\n}");
        $this->assertSame([79.0, 100.0], [$written->width / Units::POINT, $written->height / Units::POINT]);
        $this->assertContains('-x : int', \array_map(static fn (Mark $mark): string => $mark->text, $written->marks));
    }

    public function testObjectsStandAsPlantUmlDrawsThem(): void
    {
        $drawing = self::draw("object user {\n  name = \"Dummy\"\n  id = 123\n}\nobject group\nuser --> group");
        $texts = \array_values(\array_map(
            static fn (Mark $mark): array => [$mark->text, \round($mark->points[0][1] / Units::POINT, precision: 2)],
            \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind),
        ));

        // As the jar draws them: the name centred 2 below the top, the fields under a line, an object with none an empty row 16 tall.
        $this->assertSame([154.0, 178.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertSame(
            [
                ['user',           22.54],
                ['name = "Dummy"', 45.02],
                ['id = 123',       61.51],
                ['group',          144.0],
            ],
            $texts,
        );
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };
        $this->assertSame([63.0, 165.0], $size("class A\nobject o\nA --> o"));
        $this->assertSame([99.0, 153.0], $size("object a\nobject b\nobject c\na --> b\na --> c"));
    }

    public function testMapsStandAsPlantUmlDrawsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };
        $map = self::draw("map CapitalCity {\n UK => London\n USA => Washington\n}");
        $texts = \array_values(\array_map(
            static fn (Mark $mark): array => [$mark->text, \round($mark->points[0][1] / Units::POINT, precision: 2)],
            \array_filter($map->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind),
        ));

        // As the jar draws them: each entry a row under a line, its key centred, its value after a line.
        $this->assertSame([146.0, 81.0], [$map->width / Units::POINT, $map->height / Units::POINT]);
        $this->assertSame(
            [
                ['CapitalCity', 22.54],
                ['UK',          43.02],
                ['London',      43.02],
                ['USA',         63.51],
                ['Washington',  63.51],
            ],
            $texts,
        );
        // Laid out as svek writes it, a table of rows in whole points; empty, just its title.
        $this->assertSame([181.0, 215.0], $size("map \"Capital Cities\" as CC {\n UK => London\n USA => Washington\n Germany => Berlin\n}\nclass X\nX --> CC"));
        $this->assertSame([76.0, 40.0], $size("map Empty {\n}"));
        $this->assertSame([83.0, 97.0], $size("map M {\n +a => 1\n multi\\nline => v\n}"));
        $this->assertSame([142.0, 83.0], $size("map M {\n a => 1\n b => 2\n}\nclass X"));
        // A lone object taken at its size; an object's fields measured as wide as they are.
        $this->assertSame([42.0, 56.0], $size('object o'));
        $this->assertSame([151.0, 162.0], $size("object \"Long Object Title\" as o {\n a = 1\n}\nobject p\no --> p"));
    }

    public function testAMiddleCircleStandsMidwayAsPlantUmlDrawsIt(): void
    {
        $centres = static fn (Drawing $drawing): array => \array_values(\array_map(
            static fn (Mark $mark): array => [
                \round(((\min(\array_column($mark->points, 0)) + \max(\array_column($mark->points, 0))) / 2) / Units::POINT, precision: 1),
                \round(((\min(\array_column($mark->points, 1)) + \max(\array_column($mark->points, 1))) / 2) / Units::POINT, precision: 1),
            ],
            \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && (1.5 * Units::POINT) === $mark->strokeWidth),
        ));

        // As the jar draws them: 6 across where the line comes nearest midway between its ends; the jar's at 27.83,145.955 and 27.83,112.95.
        $this->assertSame([[27.8, 146.0]], $centres(self::draw("class A\nclass B\nA --0-- B")));
        $this->assertSame([[27.8, 113.0]], $centres(self::draw("class A\nclass B\nA ..0.> B")));
        $this->assertSame([[45.5, 169.5], [82.5, 85.0]], $centres(self::draw("class A\nclass B\nclass C\nA --0-- B\nA -0- C")));
    }

    public function testAssociationClassesStandAsPlantUmlSetsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: the link split at a dot 4 across, the dot linked to the association class.
        $this->assertSame([206.0, 286.0], $size("class Student\nclass Course\nclass Enrollment\n(Student, Course) .. Enrollment"));
        $this->assertSame([138.0, 304.0], $size("class A\nclass B\nclass C\nA \"1\" *-- \"many\" B : has\n(A, B) .. C"));
        $this->assertSame([177.0, 178.0], $size("class A\nclass B\nclass C\nA - B\n(A, B) .. C"));
        $this->assertSame([121.0, 286.0], $size("class A\nclass B\nclass C\nC .. (A, B)"));
    }

    public function testClassesNoLinkTouchesStandInASquareAsPlantUmlPutsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: three or more in a group, in rows as long as the square root of how many, rounded up.
        $this->assertSame([216.0, 178.0], $size("class A\nclass B\nclass C\nclass D\nclass E"));
        $this->assertSame([167.0, 178.0], $size("class A\nclass B\nnote \"floating\" as N\nclass C"));
        $this->assertSame([229.0, 228.0], $size("package P {\n class A\n class B\n class C\n}\nclass X"));
        // Linked, or with a note beside them, they stand as laid out.
        $this->assertSame([216.0, 178.0], $size("class A\nclass B\nclass C\nclass D\nA --> D"));
        $this->assertSame([279.0, 70.0], $size("class A\nclass B\nclass C\nnote right of A : n"));
    }

    public function testPackagesMarkedAsSymbolsAreDrawnAsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = self::draw($lines);

            return [\round($drawing->width / Units::POINT, precision: 3), \round($drawing->height / Units::POINT, precision: 3)];
        };

        // As the jar draws them: a node, a cloud and a database each its own outline, the room its shape takes kept.
        $this->assertSame([125.0, 135.0], $size("package B <<Node>> {\n class Y\n}"));
        $this->assertSame([103.0, 127.0], $size("package C <<Cloud>> {\n class Z\n}"));
        $this->assertSame([105.0, 145.0], $size("package E <<Database>> {\n class V\n}"));
    }

    public function testALinkToAFloatingNoteEndsAtTheNote(): void
    {
        // As the jar sets it: no class made of the note's name.
        $drawing = self::draw("class Car\nnote \"Floating\" as N1\nCar .. N1");
        $this->assertSame([92.0, 155.0], [\round($drawing->width / Units::POINT, precision: 3), \round($drawing->height / Units::POINT, precision: 3)]);
        $this->assertNotContains('N1', \array_map(static fn (Mark $mark): string => $mark->text, $drawing->marks));
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }

    public function testElementsMixedInAreDrawnAsADescriptionDiagramDrawsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: an actor, a use case, a component, a node and a database among classes; a rectangle of several lines, without allowmixing, in sections where a line separates them.
        $this->assertSame([146.0, 414.0], $size("allowmixing\nclass A\nusecase U\ncomponent C\nnode N\ndatabase D <<db>>\nA --> U\nA --> C\nC --> N\nN --> D"));
        $this->assertSame([118.0, 356.0], $size("allowmixing\npackage P {\n  class A\n  rectangle R #pink\n}\nactor/ B\nB --> A\nA ..> R : uses"));
        $this->assertSame([63.0, 204.0], $size("class A\nmix_actor U\nU --> A"));
        $this->assertSame([81.0, 117.0], $size("rectangle R [\n  one\n  ----\n  two\n  ==t==\n  three\n]"));
    }

    public function testAnObjectsStereotypesStandAboveItsName(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: the stereotype in italics at 12 points over the name, 5 in from the sides; a diamond taken 10 wider either side, as a polygon.
        $this->assertSame([83.0, 185.0], $size("object a <<stereo>>\nobject b #pink\na --> b : label"));
        $this->assertSame([65.0, 237.0], $size("diamond D\nobject A\nobject B\nA --> D\nD --> B"));
        // `<> name`, an association's diamond, 24 across.
        $this->assertSame([167.0, 262.0], $size("class Foo\nclass Bar\nclass Baz\n<> diamond\nFoo -- diamond\nBar -- diamond\ndiamond -- Baz"));
        $this->assertSame([208.0, 280.0], $size("class Student\nclass Course\nclass Grade\n<> enrol\nStudent \"1\" -- enrol\nenrol -- \"*\" Course\nenrol .. Grade : grades"));
    }

    public function testANoteBesideAPackageStandsBesideItsPoint(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: beside a point inside the package, as svek lays it out, its line cut at the package's frame; beside an empty package, as beside a class.
        $this->assertSame([186.0, 136.0], $size("package P {\nclass A\n}\nnote right of P : n"));
        $this->assertSame([198.0, 209.0], $size("package P {\nclass A\n}\nnote left of P : left\nnote top of P : top\nclass B\nB --> A"));
        $this->assertSame([147.0, 212.0], $size("package P {\nclass A\n}\nnote bottom of P\n  two\n  lines\nend note"));
        $this->assertSame([164.0, 73.0], $size("package P {\n}\nnote right of P : on empty"));
    }

    public function testContainersStandAsTheirSymbolsDrawThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: as a description diagram's containers, a folder rounded, a card's title ruled off, a hexagon taken as its path; an empty package laid out with dot.
        $this->assertSame([127.0, 135.0], $size("node N {\nclass A\n}"));
        $this->assertSame([95.0, 242.0], $size("folder F {\nclass A\n}\nframe G {\nclass B\n}\nA --> B"));
        $this->assertSame([107.0, 269.0], $size("cloud C {\nclass A\n}\ndatabase D {\nclass B\n}\nA --> B"));
        $this->assertSame([580.0, 121.0], $size("artifact K {\nclass A\n}\nfile X {\nclass B\n}\nstorage S {\nclass C\n}\nqueue Q {\nclass D\n}\nstack T {\nclass E\n}\nhexagon H {\nclass F\n}"));
        $this->assertSame([96.0, 136.0], $size("card \"Two\\nlines\" as X {\nclass A\n}"));
        $this->assertSame([49.0, 73.0], $size("package P {\n}"));
    }

    public function testAnEmptyContainerOfASymbolIsLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw an empty frame such as F'));

        Work::run(PlantUmlPipeline::native()->drawing("@startuml\nframe F {\n}\nclass A\n@enduml"));
    }

    public function testALollipopIsACircleItsNameBelow(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: a circle 10 across, its name as written centred below it, its link plain, labelled at its ends and middle.
        $this->assertSame([75.0, 172.0], $size("class A\nA --() \"Serial\" : uses"));
        $this->assertSame([68.0, 139.0], $size("class A\n\"Serial\" ()-- A"));
        $this->assertSame([100.0, 281.0], $size("class A\nclass B\nA --() Foo : \"1\" provided \"2\"\nB --> A"));
    }

    public function testTheGuillemetSkinParamWritesStereotypesAsItSays(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: between `<<` and `>>` for false, alone for none, in a class, a description and a sequence diagram alike.
        $this->assertSame([119.0, 76.0], $size("skinparam guillemet false\nclass A <<entity>>"));
        $this->assertSame([80.0, 76.0], $size("skinparam guillemet none\nclass A <<entity>>"));
        $this->assertSame([111.0, 177.0], $size("skinparam guillemet false\nparticipant P <<svc>>\nP -> P : x"));
        $this->assertSame([132.0, 82.0], $size("skinparam guillemet false\nnode N <<box>>"));
    }
}
