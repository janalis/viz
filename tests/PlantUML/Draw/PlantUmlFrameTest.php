<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
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

    public function testAnEmptyDocumentKeepsItsMargin(): void
    {
        $document = PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nskinparam backgroundColor transparent\n@enduml"));

        $drawing = PlantUmlFrame::drawing($document, [], 0.0, 0.0);
        $this->assertEqualsWithDelta(20 * Units::POINT, $drawing->width, 1e-9);
        $this->assertNull($drawing->background);
    }
}
