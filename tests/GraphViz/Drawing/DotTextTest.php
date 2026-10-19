<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\Drawing\DotText;
use Janalis\Viz\GraphViz\Drawing\Justify;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

final class DotTextTest extends TestCase
{
    public function testEscapesExpandAndLineEndsJustify(): void
    {
        $text = DotText::parse("\\N on \\G\\lright\\rmiddle\\nraw\nlast \\q\\", ['N' => 'a', 'G' => 'g'], fontSize: 10.0, fontName: 'Times-BoldItalic', color: '#ff0000');

        $this->assertSame(
            [
                ['a on g',   Justify::Left],
                ['right',    Justify::Right],
                ['middle',   Justify::Center],
                ['raw',      Justify::Center],
                ['last q\\', Justify::Center],
            ],
            $text->lines,
        );
        $this->assertSame(FontFace::BoldItalic, $text->face);
        $this->assertSame('#ff0000', $text->color);
        $this->assertEqualsWithDelta([7 * Units::cell(10.0), 5 * Units::line(10.0)], $text->size(), 1e-9);
    }

    public function testAnEmptyLabelHasNoSizeAndATrailingEndAddsNoLine(): void
    {
        $this->assertTrue(DotText::parse('', [])->empty());
        $this->assertSame([0.0, 0.0], DotText::parse('', [])->size());
        $this->assertSame([['x', Justify::Left]], DotText::parse('x\\l', [])->lines);
        $this->assertFalse(DotText::parse('x', [])->empty());
    }

    public function testFontNamesPickTheClosestFace(): void
    {
        $this->assertSame(FontFace::Bold, DotText::face('Helvetica-Bold'));
        $this->assertSame(FontFace::Italic, DotText::face('Courier Oblique'));
        $this->assertSame(FontFace::Italic, DotText::face('serif italic'));
        $this->assertSame(FontFace::Regular, DotText::face('Times'));
    }
}
