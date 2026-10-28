<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class FontFamilyTest extends TestCase
{
    public function testAFontsNameIsMeasuredInTheFamilyJavaFindsForIt(): void
    {
        $this->assertSame(
            ['monospaced', 'monospaced', 'serif', 'serif', 'sans', 'sans', 'sans'],
            \array_map(PlantUmlMetrics::family(...), ['Monospaced', '"Courier"', 'serif', 'Times New Roman', 'Times', 'SansSerif', '']),
        );
    }

    public function testASequenceDiagramIsSizedInItsFontsFamily(): void
    {
        $drawn = static fn (string $font): Drawing => Work::run(PlantUmlPipeline::native()->drawing(
            "@startuml\nskinparam defaultFontName {$font}\nparticipant \"nnnnnnnnnnnnnnnnnnnn\" as A\n@enduml",
        ));
        $size = static fn (Drawing $drawing): array => [\round($drawing->width / Units::POINT, precision: 6), \round($drawing->height / Units::POINT, precision: 6)];
        $baseline = static fn (Drawing $drawing): float => \round(
            \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind))[0]->points[0][1] / Units::POINT,
            precision: 2,
        );

        // As the jar sizes them: each family's advances and line, its leading above its text; a whole size 1 past, as any other.
        $this->assertSame([[208.0, 101.0], [203.0, 101.0], [175.0, 101.0], [208.0, 101.0]], \array_map(static fn (string $font): array => $size($drawn($font)), [
            'SansSerif',
            'Courier',
            'serif',
            'Times',
        ]));
        $this->assertSame([30.54, 30.0, 30.07], \array_map(static fn (string $font): float => $baseline($drawn($font)), ['SansSerif', 'Courier', 'serif']));
    }
}
