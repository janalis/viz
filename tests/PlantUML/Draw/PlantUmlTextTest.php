<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

final class PlantUmlTextTest extends TestCase
{
    public function testCreoleMarkupIsTakenOutAndWholeLinesTakeItsFace(): void
    {
        $this->assertSame('bold italic mono ', PlantUmlText::plain('**bold** //italic// ""mono"" <&x>'));
        $this->assertSame('red big', PlantUmlText::plain('<color:red>red</color> <size:20>big</size>'));
        $text = new PlantUmlText(['**bold**', '<i>italic</i>', '<b>//both//</b>', '', 'plain'], 12.0, color: '#ff0000');

        $marks = $text->marks(0.0, 0.0, 100.0, 'left');
        $this->assertSame(['bold', 'italic', 'both', 'plain'], \array_map(static fn (Mark $mark): string => $mark->text, $marks));
        $this->assertSame([FontFace::Bold, FontFace::Italic, FontFace::Bold, FontFace::Regular], \array_map(static fn (Mark $mark): FontFace => $mark->font, $marks));
        $this->assertSame('#ff0000', $marks[0]->stroke);
        $this->assertEqualsWithDelta([6 * Units::cell(12.0), 5 * PlantUmlText::lineHeight(12.0)], $text->size(), 1e-9);
    }

    public function testLinesSetLeftCentredOrRight(): void
    {
        $text = new PlantUmlText(['ab'], 12.0, FontFace::BoldItalic);
        $width = 2 * Units::cell(12.0);

        $this->assertEqualsWithDelta($width / 2, $text->marks(0.0, 0.0, 100.0, 'left')[0]->points[0][0], 1e-9);
        $this->assertEqualsWithDelta(50.0, $text->marks(0.0, 0.0, 100.0)[0]->points[0][0], 1e-9);
        $this->assertEqualsWithDelta(100.0 - ($width / 2), $text->marks(0.0, 0.0, 100.0, 'right')[0]->points[0][0], 1e-9);
        $this->assertSame(FontFace::BoldItalic, $text->marks(0.0, 0.0, 0.0)[0]->font);
        $this->assertTrue(new PlantUmlText([' '])->empty());
        $this->assertTrue(new PlantUmlText([])->empty());
        $this->assertSame([0.0, 0.0], new PlantUmlText([])->size());
        $this->assertSame(FontFace::Italic, PlantUmlText::styled(['x'], new Style(['FontStyle' => 'italic', 'FontSize' => '9']))->face);
    }

    public function testANoteFoldsItsCorner(): void
    {
        $note = new NoteBox(new PlantUmlText(['hello'], 12.0), new Style(), '#ffffff');

        [$width, $height] = $note->size();
        $this->assertEqualsWithDelta([(5 * Units::cell(12.0)) + 22, PlantUmlText::lineHeight(12.0) + 10], [$width, $height], 1e-9);
        $marks = $note->marks(0.0, 0.0);
        $this->assertCount(5, $marks[0]->points);
        $this->assertSame('#ffffff', $marks[0]->fill);
        $this->assertSame('#feffdd', new NoteBox(new PlantUmlText(['x']), new Style())->marks(0.0, 0.0)[0]->fill);
    }
}
