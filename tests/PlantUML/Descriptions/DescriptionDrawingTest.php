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
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DescriptionDrawingTest extends TestCase
{
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
        $this->assertEqualsWithDelta([(4 * Units::cell(14.0)) + 20, PlantUmlText::lineHeight(14.0) + 20], [$box->width, $box->height], 1e-9);
        // As PlantUML, an interface's node is a circle 16 across, 1 clear all round; its name hangs below.
        $this->assertEqualsWithDelta(
            [18.0, 18.0],
            [
                DescriptionBox::of(new DescriptionElement('I', ['I'], 'interface'), StyleSheet::skin())->width,
                DescriptionBox::of(new DescriptionElement('I', ['I'], 'interface'), StyleSheet::skin())->height,
            ],
            1e-9,
        );
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
