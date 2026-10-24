<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class PlantUmlFrameTest extends TestCase
{
    public function testCaptionsStackAroundTheDiagram(): void
    {
        $document = PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nheader Head\ntitle Title\nlegend left\nLegend\nendlegend\ncaption Caption\nright footer Foot\nscale 2\n@enduml"));
        $diagram = new Mark(MarkKind::Text, [[0.0, 0.0]], 'diagram');

        $drawing = PlantUmlFrame::drawing($document, [$diagram], 100.0, 50.0);

        $texts = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $this->assertSame(['Head', 'Title', 'diagram', 'Legend', 'Caption', 'Foot'], \array_map(static fn (Mark $mark): string => $mark->text, $texts));
        $ys = \array_map(static fn (Mark $mark): float => $mark->points[0][1], $texts);
        $sorted = $ys;
        \sort($sorted);
        $this->assertSame($sorted, $ys, 'top to bottom');
        $this->assertGreaterThan($texts[2]->points[0][0], $texts[0]->points[0][0], 'the header set right');
        $this->assertLessThan($texts[1]->points[0][0], $texts[3]->points[0][0], 'the legend set left of the centred title');
        $this->assertNotEmpty(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind), 'the legend boxed');
        $this->assertEqualsWithDelta(120 * 2 * Units::POINT, $drawing->width, 1e-6);
        $this->assertSame('#ffffff', $drawing->background);
    }

    public function testItsSizeIsInWholePointsRoundedUpAsPlantUmlWritesIt(): void
    {
        $drawing = PlantUmlFrame::drawing(PlantUmlDocument::read(PlantUmlSource::parse("@startuml\n@enduml")), [], 100.2, 50.0);

        $this->assertEqualsWithDelta([121 * Units::POINT, 70 * Units::POINT], [$drawing->width, $drawing->height], 1e-9);
    }

    public function testAScaledSizeIsItsWholeSizeScaledAndRoundedDownAsPlantUmlWritesIt(): void
    {
        // 120.2 by 70, 121 by 70 in whole points, at 100 dpi: 126.04 by 72.92, rounded down.
        $drawing = PlantUmlFrame::drawing(PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nskinparam dpi 100\n@enduml")), [], 100.2, 50.0);

        $this->assertEqualsWithDelta([126 * Units::POINT, 72 * Units::POINT], [$drawing->width, $drawing->height], 1e-9);
    }

    public function testAScaleToASizeFitsTheDrawingAsPlantUmlFitsIt(): void
    {
        $size = static function (string $scale, string $body): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$scale}\n{$body}\n@enduml"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };
        $sequence = 'Alice -> Bob : hi';
        $classes = "class Order {\n +id : int\n}\nclass Item\nOrder --> Item";

        // As the jar sizes them: from the size unscaled, a svek diagram's rounded down first; both ways, the smaller; `max` only shrinking; the last scale written.
        $this->assertSame(
            [[201.0, 225.0], [89.0, 100.0], [201.0, 225.0], [50.0, 56.0], [117.0, 131.0], [234.0, 262.0], [300.0, 632.0], [71.0, 150.0]],
            [
                $size('scale 200 width', $sequence),
                $size('scale 100 height', $sequence),
                $size('scale 200*300', $sequence),
                $size('scale max 50 width', $sequence),
                $size('scale max 500 width', $sequence),
                $size("scale 200 width\nscale 2", $sequence),
                $size('scale 300 width', $classes),
                $size('scale 150*150', $classes),
            ],
        );
    }

    public function testAScaledSvekDiagramIsItsSizeRoundedDownScaledAndRoundedDownAsPlantUmlWritesIt(): void
    {
        $height = static function (string $scale): float {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nscale {$scale}\nclass Order {\n  +id : int\n}\nclass Item\nOrder --> Item\n@enduml"));

            return $drawing->height / Units::POINT;
        };

        // As the jar writes them: 194 unscaled, then 242.5, 291 and 155.2, rounded down.
        $this->assertSame([194.0, 242.0, 291.0, 155.0], [$height('1'), $height('1.25'), $height('1.5'), $height('0.8')]);
    }

    public function testATitleWiderThanASvekDiagramIsTakenInWholePointsRoundedUp(): void
    {
        $width = static fn (string $title): float => Work::run(PlantUmlPipeline::native()->drawing("@startuml\ntitle {$title}\n[*] --> On\n@enduml"))->width / Units::POINT;

        // As the jar sizes them: its title's width rounded up, though the diagram is then rounded down.
        $this->assertSame([78.0, 86.0, 94.0, 103.0], [$width('Machin'), $width('Machine'), $width('Machines'), $width('MachinesX')]);
    }

    public function testAnEmptyDocumentKeepsItsMargin(): void
    {
        $document = PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nskinparam backgroundColor transparent\n@enduml"));

        $drawing = PlantUmlFrame::drawing($document, [], 0.0, 0.0);
        $this->assertEqualsWithDelta(20 * Units::POINT, $drawing->width, 1e-9);
        $this->assertNull($drawing->background);
    }
}
