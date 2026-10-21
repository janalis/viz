<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlTable;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

final class PlantUmlTableTest extends TestCase
{
    public function testRowsAreToldFromOtherLines(): void
    {
        $this->assertSame([true, true, false, false], \array_map(PlantUmlTable::row(...), ['| a |', ' <#red,#blue>| a | b | ', '|', 'a | b']));
    }

    public function testATableSetsItsCellsInColumnsWithItsLinesAround(): void
    {
        $text = new PlantUmlText(['before', '<#yellow,#0000ff>|= Head | x |', '| longer | <#red> y |', 'after', '<#ffffff>| alone |', '<color:#123456>coloured</color>'], 10.0, color: '#333333');
        [$width, $height] = $text->size();
        $marks = $text->marks(0.0, 0.0, $width, 'left');
        $words = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $fills = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind));
        $lines = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));

        $this->assertSame(['before', ' Head ', ' x ', ' longer ', ' y ', 'after', ' alone ', 'coloured'], \array_map(static fn (Mark $mark): string => (string) $mark->text, $words));
        $this->assertSame(FontFace::Bold, $words[1]->font);
        $this->assertSame(['#ffff00', '#ffff00', '#ff0000', '#ffffff'], \array_map(static fn (Mark $mark): ?string => $mark->fill, $fills));
        $this->assertSame('#0000ff', $lines[0]->stroke, 'the first row gives the lines their colour');
        $this->assertSame('#333333', $lines[\count($lines) - 1]->stroke, 'or the text does');
        $this->assertSame('#123456', $words[7]->stroke);
        $this->assertCount(3 + 3 + 2 + 2, $lines, 'a line above and below each row, and beside each column');
        $this->assertEqualsWithDelta(11 * Units::cell(10.0), $width, 1e-9, 'its widest column, " longer ", and the next');
        $this->assertEqualsWithDelta((6 * PlantUmlText::lineHeight(10.0)) + 8.0, $height, 1e-9, '2 above and below each table');
    }
}
