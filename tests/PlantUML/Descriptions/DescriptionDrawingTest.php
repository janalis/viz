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

    public function testANodeContainerIsTakenTenWiderEitherSide(): void
    {
        $width = static fn (string $symbol): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$symbol} Server {\n  [App]\n}\n@enduml"))->width / Units::POINT;

        // As the jar sizes them: a node 162 wide, a frame round the same 121.
        $this->assertSame([162.0, 121.0], [$width('node'), $width('frame')]);
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
}
