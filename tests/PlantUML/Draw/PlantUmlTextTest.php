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

    public function testTextWrapsAtItsStylesMaximumWidthAsPlantUmlWraps(): void
    {
        $style = new Style(['MaximumWidth' => '100', 'FontSize' => '14']);

        $this->assertSame(
            [
                'A customer of',
                'the bank, with',
                'personal bank',
                'accounts.',
                '== Personal',
                '== Banking',
                '== Customer',
                '**bold across a**',
                '**break** and',
                'Supercalifragilisticexpialidocious',
                '<size:12>small and long</size>',
                '<size:12>text</size>',
                '<size:9>a</size> <size>b</size>',
            ],
            PlantUmlText::styled([
                'A customer of the bank, with personal bank accounts.',
                '== Personal Banking Customer',
                '**bold across a break** and',
                'Supercalifragilisticexpialidocious',
                '<size:12>small and long text</size>',
                '<size:9>a</size> <size>b</size>',
            ], $style)->lines,
        );
        $this->assertSame(['short'], PlantUmlText::styled(['short'], new Style(['MaximumWidth' => '0']))->lines);
        $this->assertSame(['| a table row is never wrapped however long it runs |'], PlantUmlText::styled(['| a table row is never wrapped however long it runs |'], $style)->lines);
        $this->assertSame(
            ['As PlantUML', 'wraps: the', 'same words', 'on each line'],
            PlantUmlText::styled(['As PlantUML wraps: the same words on each line'], $style)->lines,
            'as the jar wraps it at 100',
        );
    }

    public function testJavasMetricsSetEachLineOnItsTallestTextAsPlantUmlSetsIt(): void
    {
        // As the jar sets a box's text, from the text's top: a heading, then a line wholly 12 points, a blank line, a plain one.
        $text = new PlantUmlText(['== Web', '//<size:12>[PHP]</size>//', '', 'Serves pages']);
        $marks = $text->marks(0.0, 0.0, 100.0);

        $this->assertSame(['Web', '[PHP]', 'Serves pages'], \array_map(static fn (Mark $mark): string => $mark->text, $marks));
        $this->assertEqualsWithDelta([15.469, 30.445, 63.0], \array_map(static fn (Mark $mark): float => $mark->points[0][1], $marks), 1e-3);
        $this->assertEqualsWithDelta(Units::fontScale(12.0) / Units::POINT, $marks[1]->fontScale, 1e-9, 'drawn at its own size');
        $this->assertEqualsWithDelta(65.953, $text->size()[1], 1e-3);

        // A line of small text 10 high at least, the room below it.
        $small = new PlantUmlText(['a', 'b'], 6.0);
        $this->assertEqualsWithDelta([5.801, 15.801], \array_map(static fn (Mark $mark): float => $mark->points[0][1], $small->marks(0.0, 0.0, 10.0)), 1e-3);
        $this->assertEqualsWithDelta(20.0, $small->size()[1], 1e-9);
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
