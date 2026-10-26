<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Descriptions;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionElement;
use Janalis\Viz\PlantUML\Descriptions\DescriptionReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

final class DescriptionDrawingTest extends TestCase
{
    public function testABigCloudAndAnEmptyUseCaseTakeTheirShapes(): void
    {
        // A cloud over 100 each way pulls its sides in from its corners; it takes the room its curves' handles reach.
        $cloud = DescriptionBox::of(new DescriptionElement('C', ['A cloud with a long name', 'over', 'several', 'lines', 'of text'], 'cloud'), StyleSheet::skin());
        $this->assertGreaterThan(100.0, $cloud->width);
        $this->assertGreaterThan(100.0, $cloud->height);
        $points = \array_merge(...\array_map(static fn (Mark $mark): array => $mark->points, $cloud->marks(0.0, 0.0)));
        $this->assertGreaterThan($cloud->height, \max(\array_column($points, 1)));
        // A cloud too small for a bubble a side spreads two along each half side.
        $small = DescriptionBox::of(new DescriptionElement('S', [''], 'cloud'), StyleSheet::skin());
        $this->assertNotEmpty($small->marks(0.0, 0.0)[0]->points);
        // A use case with no text is its ellipse's 6 alone.
        $this->assertSame(6.0, DescriptionBox::of(new DescriptionElement('U', [''], 'usecase'), StyleSheet::skin())->height);
    }

    public function testEverySymbolHasItsShapeAndMargins(): void
    {
        foreach (DescriptionReader::SYMBOLS as $symbol) {
            $element = new DescriptionElement('E', ['Name'], \rtrim($symbol, characters: '/'));
            $element->stereotypes = 'node' === $symbol ? ['server'] : [];
            $box = DescriptionBox::of($element, StyleSheet::skin());
            $marks = $box->marks(0.0, 0.0);

            $this->assertGreaterThan(0.0, $box->width, $symbol);
            $this->assertContains('Name', \array_map(static fn (Mark $mark): string => $mark->text, $marks), $symbol);
        }
        $box = DescriptionBox::of(new DescriptionElement('R', ['Name'], 'rectangle'), StyleSheet::skin());
        // A line as Java measures the sans-serif font, 36.488 high with its margins, as the jar draws it.
        $this->assertEqualsWithDelta([PlantUmlMetrics::width('Name', 14.0) + 20, ((14 * 603) / 512) + 20], [$box->width, $box->height], 1e-9);
        // As PlantUML: an interface's node the table svek writes for it, as dot sizes it; linked beside another, a circle 16 across, 1 clear all round, its name hanging below.
        $interface = DescriptionBox::of(new DescriptionElement('I', ['I'], 'interface'), StyleSheet::skin());
        $this->assertEqualsWithDelta([54.0, 58.0, 18.0, 18.0], [$interface->width, $interface->height, $interface->beside()->width, $interface->beside()->height], 1e-9);
    }

    public function testAStereotypeWiderThanItsNameTakesTwoMore(): void
    {
        $width = static function (string $name, string $symbol, string $stereotype): float {
            $element = new DescriptionElement('E', [$name], $symbol);
            $element->stereotypes = [$stereotype];

            return DescriptionBox::of($element, StyleSheet::skin())->width;
        };

        // As the jar draws them: a stereotype the widest text 2 wider, an underscore measured as Java sets it; a name the widest as it is.
        $this->assertEqualsWithDelta(103.599, $width(name: 'Billing', symbol: 'component', stereotype: 'service'), 2e-3);
        $this->assertEqualsWithDelta(138.198, $width(name: 'R', symbol: 'rectangle', stereotype: 'longstereotype'), 2e-3);
        $this->assertEqualsWithDelta(146.981, $width(name: 'R', symbol: 'rectangle', stereotype: 'external_system'), 2e-3);
        $this->assertEqualsWithDelta(129.648, $width(name: 'Long name here', symbol: 'rectangle', stereotype: 's'), 2e-3);
    }

    public function testANodeContainerIsTakenTenWiderEitherSide(): void
    {
        $width = static fn (string $symbol): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$symbol} Server {\n  [App]\n}\n@enduml"))->width / Units::POINT;

        // As the jar sizes them: a node 162 wide, a frame round the same 121.
        $this->assertSame([162.0, 121.0], [$width('node'), $width('frame')]);
    }

    public function testPolygonsAreTakenTenWiderEitherSideAsPlantUmlsLimitFinderTakesThem(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [
            $drawing->width / Units::POINT,
            $drawing->height / Units::POINT,
        ])(Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")));
        $width = static fn (string $symbol): float => $size("{$symbol} Thing\nrectangle R\nR --> Thing")[0];

        // As the jar sizes them: a node, a hexagon, an action and a process, which it draws as polygons, 20 wider than a rectangle.
        $this->assertSame([120.0, 119.0, 110.0, 120.0, 80.0], [$width('node'), $width('hexagon'), $width('action'), $width('process'), $width('rectangle')]);
        // A boundary drawn 4 in from its node's sides, a control's arrowhead a polygon too.
        $beside = static fn (string $symbol): float => $size("{$symbol} X\nrectangle R\nX -> R")[0];
        $this->assertSame([128.0, 113.0], [$beside('boundary'), $beside('control')]);
        // An artifact's document, a polygon 5 in from its top right, 10 wider either side.
        $this->assertSame([[65.0, 156.0], [58.0, 59.0]], [$size("artifact X\nrectangle R\nX --> R"), $size('artifact X')]);
        // A lone element as its box, 7 in from its sides; but a hexagon, which PlantUML lays out with the others.
        $this->assertSame([[99.0, 66.0], [81.0, 49.0], [119.0, 47.0]], [$size('node Thing'), $size('usecase Thing'), $size('hexagon Thing')]);
    }

    public function testAPackageShowsItsNameInItsTabAndItsTextBelowAsPlantUmlDrawsIt(): void
    {
        $size = static fn (string $source): array => (static fn (Drawing $drawing): array => [
            $drawing->width / Units::POINT,
            $drawing->height / Units::POINT,
        ])(Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml")));

        // As the jar sizes them: its name in bold, 6 either side, in its tab; its text below, where it is not its name; 10 before, 20 past, 13 above and 10 below.
        $this->assertSame([72.0, 155.0], $size("package X\nrectangle R\nX --> R"));
        $this->assertSame([[192.0, 76.0], [172.0, 76.0], [146.0, 93.0]], [
            $size("package \"Hello World\" as P\nrectangle R\nP -> R"),
            $size("package X <<stereo>>\nrectangle R\nX -> R"),
            $size("package \"A\\nBBBB\" as P\nrectangle R\nP -> R"),
        ]);
        // Alone, laid out as any other, with dot.
        $this->assertSame([71.0, 59.0], $size('package X'));
        $texts = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startuml\npackage \"Hello\" as P\nrectangle R\nP -> R\n@enduml"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $texts[$mark->text] = [$mark->font, \round($mark->points[0][1] / Units::POINT, precision: 3)];
        }
        $this->assertSame([
            [FontFace::Bold,    22.535],
            [FontFace::Regular, 49.023],
        ], [$texts['P'], $texts['Hello']]);
    }

    public function testStereotypesStandWherePlantUmlsSymbolsSetThem(): void
    {
        $drawing = static fn (string $symbol): Drawing => Work::run(PlantUmlPipeline::native()->drawing(
            "@startuml\n{$symbol} \"A longer name\\nand two lines\" as X <<stereo>>\nrectangle R\nX --> R\n@enduml",
        ));
        $size = static fn (string $symbol): array => [$drawing($symbol)->width / Units::POINT, $drawing($symbol)->height / Units::POINT];

        // As the jar sizes them: an icon's stereotypes above it; an interface's outside its node, above its circle; a use case's ellipse around each line's corners.
        $this->assertSame([[120.0, 199.0], [120.0, 227.0], [120.0, 196.0], [151.0, 184.0]], [$size('boundary'), $size('actor'), $size('interface'), $size('usecase')]);
        $baselines = [];
        foreach ($drawing('usecase')->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $baselines[] = \round($mark->points[0][1] / Units::POINT, precision: 3);
        }
        // Its lines drawn from the ellipse's centre, 2 higher, as the jar draws them.
        $this->assertSame([25.392, 41.88, 58.368], \array_slice($baselines, offset: 0, length: 3));
    }

    public function testAFoldersTabIsAsPlantUmlsFolderDrawsIt(): void
    {
        $outline = static function (string $source): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"));
            // The line under its tab: the highest across.
            $lines = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && 2 === \count($mark->points) && $mark->points[0][1] === $mark->points[1][1]);
            \usort($lines, static fn (Mark $a, Mark $b): int => $a->points[0][1] <=> $b->points[0][1]);
            $tab = $lines[0];

            return [\round($drawing->width / Units::POINT, precision: 3), \round(($tab->points[1][0] - $tab->points[0][0]) / Units::POINT, precision: 3)];
        };

        // As the jar draws them: a folder's tab under the 40 by 15 it keeps for a title, 6 more, and its slant 7; a container's as its title; unrounded, a polygon taken 10 wider either side.
        $this->assertSame([153.0, 53.0], $outline("folder X\nrectangle R\nX -> R"));
        $this->assertSame([123.0, 99.475], $outline("folder \"Some folder\" as F {\nrectangle A\n}"));
        $this->assertSame([165.0, 53.0], $outline("skinparam roundCorner 0\nfolder X\nrectangle R\nR -> X"));
        $this->assertSame(103.0, $outline("skinparam roundCorner 0\nfolder \"F\" as F {\nrectangle A\n}")[0]);
    }

    public function testAContainersTitleAndContentStandWherePlantUmlDrawsThem(): void
    {
        $at = static function (string $symbol): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$symbol} \"Tq\" as g {\n rectangle x\n}\n@enduml"));
            $baselines = [];
            foreach ($drawing->marks as $mark) {
                if (MarkKind::Text !== $mark->kind) {
                    continue;
                }
                $baselines[$mark->text] = \round($mark->points[0][1] / Units::POINT, precision: 3);
            }

            return [$baselines['Tq'], $baselines['x']];
        };

        // As the jar draws them, a package's, a node's and a queue's outlines taken as the paths it draws.
        $this->assertSame([22.535, 65.535], $at('rectangle'));
        $this->assertSame([21.535, 64.535], $at('package'));
        $this->assertSame([32.535, 69.535], $at('node'));
        $this->assertSame([41.535, 79.535], $at('database'));
        $this->assertSame([21.535, 64.535], $at('queue'));
    }

    public function testAHexagonAnActionAndAPersonAreAsBigAsPlantUmlDrawsThem(): void
    {
        $height = static fn (string $symbol): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$symbol} X\n{$symbol} Y\nX --> Y\n@enduml"))->height / Units::POINT;

        // As the jar sizes them: a hexagon 5 above and below its text, an action as a path, a person's head above its body.
        $this->assertSame([133.0, 153.0, 180.0], [$height('hexagon'), $height('action'), $height('person')]);
        // A label's text taken as PlantUML's limit finder takes it: its line 1.5 below its top.
        $this->assertSame(133.0, $height('label'));
        // An actor's figure half a point below its node's top.
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nactor A\nrectangle R\nA --> R\n@enduml"));
        $this->assertSame(192.0, $drawing->height / Units::POINT);
    }

    public function testIconsAreAsBigAsPlantUmlDrawsThem(): void
    {
        $height = static fn (string $lines): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"))->height / Units::POINT;

        // As the jar sizes them: 1.2026's icons, 32 tall with their circles 24 across.
        $this->assertSame([160.0, 165.0, 160.0], [$height("boundary X\nrectangle R\nX --> R"), $height("control X\nrectangle R\nX --> R"), $height("entity X\nrectangle R\nX --> R")]);
        // An interface as dot sizes the table svek writes for it, or its circle alone when a link joins it beside another.
        $this->assertSame([153.0, 72.0], [$height("interface X\nrectangle R\nX --> R"), $height("interface X\nrectangle R\nX - R")]);
    }

    public function testACollectionsCopyStandsBehindItDownAndRightAsPlantUmlDrawsIt(): void
    {
        $marks = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nrectangle a\ncollections X\na --> X\n@enduml"))->marks;
        $text = \array_values(\array_filter($marks, static fn (Mark $mark): bool => 'X' === $mark->text))[0];

        // As the jar draws it: the text 8 into the front copy, at the top left, the other 4 down and right behind it.
        $this->assertEqualsWithDelta(125.025, $text->points[0][1] / Units::POINT, 0.005);
    }

    public function testWholeDiagramsDrawContainersLinksAndNotes(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing(<<<'PUML'
            @startuml
            package Pack #lightblue {
              node Server
              together {
                [Comp]
              }
            }
            folder Files {
              file F
            }
            cloud Sky {
              database DB
            }
            actor User
            User --> Server : uses
            Server --> DB
            note right of User : someone
            note "loose" as L
            L .. DB
            note on link : on it
            @enduml
            PUML));

        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));
        foreach (['Pack', 'Server', 'Comp', 'Files', 'F', 'Sky', 'DB', 'User', 'uses', 'someone', 'loose', 'on it'] as $text) {
            $this->assertContains($text, $texts);
        }
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => '#add8e6' === $mark->fill));
        $this->assertInstanceOf(Drawing::class, $drawing);
    }

    public function testAHiddenLinkIsNotDrawn(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\ncomponent A\ncomponent B\nA -[hidden]-> B\n@enduml"));

        $this->assertSame([], \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && (1.0 * Units::POINT) === $mark->strokeWidth));
    }

    public function testElementsNoLinkTouchesStandInASquareAsPlantUmlPutsThem(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\ncomponent A\ncomponent B\ncomponent C\ncomponent D\n@enduml"));

        // As the jar sets them: two rows of two.
        $this->assertSame([156.0, 173.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testLinksToContainersEndAtTheirOutlineAsPlantUmlDrawsThem(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sets them: laid out to a point inside the container, cut at its outline.
        $this->assertSame([166.0, 324.0], $size("node N {\n component C\n database DB\n}\ncloud Cl\nC --> DB\nCl --> N"));
        $this->assertSame([144.0, 287.0], $size("package P {\n [A]\n}\npackage Q {\n [B]\n}\nP --> Q"));
        $this->assertSame([160.0, 343.0], $size("rectangle R {\n actor U\n}\nfolder F {\n [C]\n}\nR ..> F : uses"));
    }

    public function testANoteBesideAnElementStandsNextToItAsPlantUmlSetsIt(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n[A] --> [B]\n[B] --> [C]\nnote right of [B] : a note\n[A] --> [C]\n@enduml"));

        // As the jar sets it: the note just right of B, A's link to C past it.
        $this->assertSame([188.0, 280.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testTheComponentStyleSetsHowAComponentIsDrawn(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: a box 10 around its name; in UML 1, two tabs half out of its left side.
        $this->assertSame([50.0, 153.0], $size("skinparam componentStyle rectangle\n[A] --> [B]"));
        $this->assertSame([55.0, 153.0], $size("skinparam componentStyle uml1\n[A] --> [B]"));
    }

    public function testALinkAcrossOverUseCasesGoesOverThem(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nactor User\nUser -> (Login)\nUser -> (Logout)\n(Login) .> (Auth) : include\n@enduml"));

        // As the jar sets it, 386 by 122: Logout next to the user, the link to Login over it, measured by its curve's controls as the jar measures it.
        $this->assertSame([386.0, 122.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testBusinessActorsAndUseCasesAreCrossedAsPlantUmlCrossesThem(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nactor A\nactor/ B\nusecase/ U\n(V)\nA --> U\nB --> V\n@enduml"));
        $chords = \array_values(\array_filter(
            $drawing->marks,
            static fn (Mark $mark): bool => (
                MarkKind::Polyline === $mark->kind
                && 2 === \count($mark->points)
                && $mark->points[0][0] !== $mark->points[1][0]
                && $mark->points[0][1] !== $mark->points[1][1]
            ),
        ));

        // As the jar draws it, 123 by 185: the business use case 7 wider either side of its name, both crossed by a chord.
        $this->assertSame([123.0, 185.0], [\round($drawing->width / Units::POINT, precision: 6), \round($drawing->height / Units::POINT, precision: 6)]);
        $this->assertCount(2, $chords);
    }

    public function testTheActorStyleSetsHowAnActorIsDrawn(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar draws them: a head over shoulders 54 wide; a hollow figure 25 wide; a business actor a stick man whatever the style.
        $this->assertSame([75.0, 181.0], $size("skinparam actorStyle awesome\nactor A\nA --> (U)"));
        $this->assertSame([49.0, 153.0], $size("skinparam actorStyle hollow\nactor A\nA --> (U)"));
        $this->assertSame([168.0, 131.0], $size("skinparam actorStyle hollow\nactor A\nactor/ C\nA -> B\nA -> C"));
    }

    public function testABusinessActorLeavesNamesOnlyLinksUseInterfaces(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nactor/ A\nA -> B\n@enduml"));

        // As the jar draws it, 99 by 95: B a circle, as no actor in the style nor use case is there.
        $this->assertSame([99.0, 95.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }
}
