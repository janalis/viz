<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Html;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\Drawing\DotLabel;
use Janalis\Viz\GraphViz\Drawing\Justify;
use Janalis\Viz\GraphViz\Html\HtmlFont;
use Janalis\Viz\GraphViz\Html\HtmlParser;
use Janalis\Viz\GraphViz\Html\HtmlTable;
use Janalis\Viz\GraphViz\Html\HtmlText;
use Janalis\Viz\Source\Unsupported;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlParserTest extends TestCase
{
    public function testTextSetsEachSpanInTheFontItsElementsMake(): void
    {
        $label = self::parse('<!-- a comment -->a<B>b<I>c</I></B><FONT COLOR="red" POINT-SIZE="20" FACE="Times-BoldItalic">d</FONT><U>e</U><O>f</O><S>g</S>h<SUB>i</SUB><SUP>j</SUP>&amp;&lt;');
        $this->assertInstanceOf(HtmlText::class, $label);
        [[$spans]] = $label->lines;

        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', '&<'], \array_column($spans, 0));
        $fonts = \array_column($spans, 1);
        $this->assertSame(
            [FontFace::Regular, FontFace::Bold, FontFace::BoldItalic, FontFace::BoldItalic, FontFace::Regular],
            \array_map(static fn (HtmlFont $font): FontFace => $font->face(), \array_slice($fonts, offset: 0, length: 5)),
        );
        $this->assertSame([20.0, '#ff0000'], [$fonts[3]->size, $fonts[3]->color]);
        $this->assertSame([true, true, true], [$fonts[4]->underline, $fonts[5]->overline, $fonts[6]->strike]);
        $this->assertGreaterThan(0, $fonts[8]->shift, 'a subscript sits below');
        $this->assertLessThan(0, $fonts[9]->shift, 'a superscript above');
        $this->assertLessThan(14.0, $fonts[8]->size);
        $marks = $label->marks(0.0, 0.0);
        $this->assertCount(11 + 3, $marks, 'a mark per span, and the three rules');
        $this->assertSame([], $label->ports(0.0, 0.0));
        $this->assertFalse($label->empty());
    }

    public function testLinesBreakAndAlign(): void
    {
        $label = self::parse("one<BR ALIGN=\"LEFT\"/>two  \n words<BR ALIGN='RIGHT'></BR><BR/>last<BR align=\"center\"/>");
        $this->assertInstanceOf(HtmlText::class, $label);

        $this->assertSame([Justify::Left, Justify::Right, null, Justify::Center, null], \array_column($label->lines, 1));
        $this->assertSame('two words', $label->lines[1][0][0][0], 'white space runs fold to one space');
        [$width, $height] = $label->size();
        $this->assertSame(5 * Units::line(14.0), $height, 'an empty line is as tall as its font');
        $this->assertSame(9 * Units::cell(14.0), $width);
        $texts = \array_values(\array_filter($label->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $this->assertEqualsWithDelta(-$width / 2, $texts[0]->points[0][0] - (1.5 * Units::cell(14.0)), 1e-9, 'one starts at the left');
        $this->assertEqualsWithDelta($width / 2, $texts[1]->points[0][0] + (4.5 * Units::cell(14.0)), 1e-9, 'two words ends at the right');
        $this->assertTrue(self::parse('')->empty());
        $this->assertSame([0.0, 0.0], self::parse(' ')->size());
        $this->assertSame([], self::parse('')->marks(0.0, 0.0));
    }

    public function testTablesLayCellsOutInAGrid(): void
    {
        $label = self::parse('
            <FONT COLOR="blue"><TABLE BORDER="1" CELLSPACING="2" CELLPADDING="2" BGCOLOR="yellow" PORT="all">
              <TR><TD PORT="a">a</TD><TD ROWSPAN="2" PORT="b">b</TD></TR>
              <HR/>
              <TR><TD BGCOLOR="red" BORDER="0">c</TD><VR/></TR>
              <TR><TD COLSPAN="2" ALIGN="LEFT" VALIGN="TOP" BALIGN="RIGHT" WIDTH="100" HEIGHT="40">wide<BR/>x</TD></TR>
              <TR><TD></TD><TD><TABLE CELLSPACING="0"><TR><TD PORT="inner">in</TD></TR></TABLE></TD></TR>
            </TABLE></FONT>');
        $this->assertInstanceOf(HtmlTable::class, $label);
        [$width, $height] = $label->size();

        $this->assertSame(100.0 + (2 * 2) + 2, $width, 'the wide cell, which takes the spacing between its columns, the spacing around it and the border');
        $ports = $label->ports(0.0, 0.0);
        $this->assertEqualsCanonicalizing(['all', 'a', 'b', 'inner'], \array_keys($ports));
        $this->assertSame([-$width / 2, -$height / 2, $width / 2, $height / 2], $ports['all']);
        $this->assertGreaterThan($ports['a'][3] - $ports['a'][1], $ports['b'][3] - $ports['b'][1], 'b spans two rows');
        $this->assertGreaterThanOrEqual($ports['b'][0], $ports['inner'][0], 'the inner table sits in the second column');
        $marks = $label->marks(0.0, 0.0);
        $this->assertSame('#ffff00', $marks[0]->fill, 'the table is filled');
        $this->assertSame('#000000', $marks[0]->stroke);
        $this->assertContains('#ff0000', \array_map(static fn (Mark $mark): string => $mark->fill, $marks), 'and the red cell');
        $texts = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $this->assertSame(['a', 'b', 'c', 'wide', 'x', 'in'], \array_map(static fn (Mark $mark): string => $mark->text, $texts));
        $this->assertSame('#0000ff', $texts[0]->stroke, 'the font around the table colours its text');
        $rules = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));
        $this->assertCount(2, $rules, 'an HR and a VR');
        $this->assertFalse($label->empty());
    }

    public function testTablesStretchAndCellsMayBeFixed(): void
    {
        $plain = self::parse('<TABLE><TR><TD>a</TD></TR></TABLE>');
        $wide = self::parse('<TABLE WIDTH="200" HEIGHT="100"><TR><TD>a</TD><TD>b</TD></TR></TABLE>');
        $fixed = self::parse('<TABLE CELLBORDER="0"><TR><TD FIXEDSIZE="TRUE" WIDTH="5" HEIGHT="x">long text</TD></TR></TABLE>');
        $empty = self::parse('<TABLE WIDTH="100"></TABLE>');

        $this->assertSame([Units::cell(14.0) + 6 + 4 + 2, Units::line(14.0) + 6 + 4 + 2], $plain->size(), 'text, padding, cell border, spacing and border');
        $this->assertSame([200.0, 100.0], $wide->size());
        $this->assertSame(5.0 + 4 + 2, $fixed->size()[0]);
        $this->assertSame([2 + 2.0, 2 + 2.0], $empty->size(), 'no cells: the spacing and the border');
    }

    public function testRowSpansPushLaterCellsAcrossAndAlignmentsPlaceContent(): void
    {
        $label = self::parse(
            '<TABLE BORDER="0" CELLBORDER="0"><TR><TD ROWSPAN="2" PORT="tall">a</TD><TD PORT="b">b</TD></TR><TR><TD PORT="c" ALIGN="RIGHT" VALIGN="BOTTOM" BALIGN="LEFT" WIDTH="80" HEIGHT="60"><B>x</B> <I>y</I><BR/>z</TD></TR></TABLE>',
        );
        $ports = $label->ports(0.0, 0.0);

        $this->assertSame($ports['b'][0], $ports['c'][0], 'c stands under b, past the cell spanning both rows');
        $marks = $label->marks(0.0, 0.0);
        $this->assertSame([MarkKind::Text], \array_values(\array_unique(\array_map(static fn (Mark $mark): MarkKind => $mark->kind, $marks), \SORT_REGULAR)), 'no border and no fill draw no frames');
        $z = \array_values(\array_filter($marks, static fn (Mark $mark): bool => 'z' === $mark->text))[0];
        $this->assertGreaterThan(($ports['c'][0] + $ports['c'][2]) / 2, $z->points[0][0], 'the text sits right in its cell');
        $this->assertGreaterThan(($ports['c'][1] + $ports['c'][3]) / 2, $z->points[0][1], 'and at the bottom');
    }

    public function testRowsAndColumnsRuleEveryCellAndSidesPickTheBorderDrawn(): void
    {
        $table = '<TABLE %s><TR><TD PORT="a">a</TD><TD ROWSPAN="2" PORT="b">b</TD><TD>c</TD></TR><TR><TD>d</TD><TD PORT="e" %s>e</TD></TR></TABLE>';
        $label = self::parse(\sprintf($table, 'ROWS="*" COLUMNS="*" CELLSPACING="4" SIDES="TB"', 'SIDES="lb" BGCOLOR="red"'));
        $ports = self::parse(\sprintf($table, 'CELLSPACING="4"', ''))->ports(0.0, 0.0);
        [$width, $height] = $label->size();
        [$left, $top] = [(-$width / 2) + 0.5, (-$height / 2) + 0.5];
        $lines = \array_values(\array_filter($label->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));
        $middle = $ports['a'][3] + 2;
        [$first, $second] = [$ports['a'][2] + 2, $ports['b'][2] + 2];

        $this->assertSame(
            [
                [[$left, $top], [-$left, $top]],
                [[-$left, -$top], [$left, -$top]],
                [[$ports['e'][2] - 0.5, $ports['e'][3] - 0.5], [$ports['e'][0] + 0.5, $ports['e'][3] - 0.5], [$ports['e'][0] + 0.5, $ports['e'][1] + 0.5]],
                [[$first, $top], [$first, $middle]],
                [[$left, $middle], [$first, $middle]],
                [[$second, $top], [$second, -$top]],
                [[$second, $middle], [-$left, $middle]],
                [[$first, $middle], [$first, -$top]],
            ],
            \array_map(static fn (Mark $mark): array => $mark->points, $lines),
            'the table\'s top and bottom, the left and bottom of e in one line, and a rule right of and below each inner cell',
        );
    }

    public function testStylesRoundDashAndHide(): void
    {
        $label = self::parse(
            '<TABLE STYLE="rounded" CELLSPACING="4" BORDER="0" CELLBORDER="1" BGCOLOR="yellow"><TR><TD STYLE="Dashed">a</TD><TD STYLE="dotted" SIDES="B">b</TD><TD STYLE=" invis">c</TD><TD STYLE="invisible">d</TD></TR></TABLE>',
        );
        $marks = $label->marks(0.0, 0.0);
        [$width, $height] = $label->size();

        $this->assertSame('#ffff00', $marks[0]->fill);
        $this->assertSame('none', $marks[0]->stroke, 'a table with no border is only filled');
        $this->assertCount(20, $marks[0]->points, 'each corner rounded');
        $this->assertEqualsWithDelta([(-$width / 2) + ($height / 3), -$height / 2], $marks[0]->points[4], 1e-9, 'a third of the shorter side');
        $this->assertSame([true, null], [$marks[1]->dashed, $marks[1]->dashes]);
        $this->assertSame([MarkKind::Polyline, true, [1.0, 3.0]], [$marks[3]->kind, $marks[3]->dashed, $marks[3]->dashes]);
        $this->assertSame(['a', 'b'], \array_values(\array_filter(\array_map(static fn (Mark $mark): string => $mark->text, $marks))), 'what an invisible cell holds is hidden too');
        $this->assertSame([], self::parse('<TABLE STYLE="rounded,invis"><TR><TD>a</TD></TR></TABLE>')->marks(0.0, 0.0));
        [$table, $cell] = self::parse('<TABLE BGCOLOR="red:blue"><TR><TD BGCOLOR="white:black" GRADIENTANGLE="180">a</TD></TR></TABLE>')->marks(0.0, 0.0);
        $this->assertSame(
            [
                ['#ff0000', '#0000ff'],
                ['#000000', '#ffffff'],
            ],
            [$table->gradient, $cell->gradient],
            'colour lists fill with gradients across',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'image' => ['<TABLE><TR><TD><IMG SRC="x.png"/></TD></TR></TABLE>', 'images in HTML labels'];
        yield 'unknown element' => ['<BLINK>a</BLINK>', 'this HTML label'];
        yield 'unclosed' => ['<B>a', 'this HTML label'];
        yield 'mismatched' => ['<B>a</I>', 'this HTML label'];
        yield 'stray close' => ['a</B>', 'this HTML label'];
        yield 'stray angle' => ['a < b', 'this HTML label'];
        yield 'text in a table' => ['<TABLE>a<TR><TD>b</TD></TR></TABLE>', 'this HTML label'];
        yield 'text in a row' => ['<TABLE><TR>a</TR></TABLE>', 'this HTML label'];
        yield 'unclosed table' => ['<TABLE><TR><TD>a</TD></TR>', 'this HTML label'];
        yield 'text after a table' => ['<TABLE></TABLE>after', 'this HTML label'];
        yield 'table in a cell, unclosed' => ['<TABLE><TR><TD><TABLE></TABLE></TR></TABLE>', 'this HTML label'];
        yield 'font around a table, unclosed' => ['<B><TABLE></TABLE>', 'this HTML label'];
        yield 'outsized grid' => ['<TABLE>'.\str_repeat('<TR><TD ROWSPAN="100" COLSPAN="100">a</TD></TR>', times: 2).'</TABLE>', 'HTML tables of more than 10000 cells'];
        yield 'deep text' => [\str_repeat('<B>', HtmlParser::MAX_DEPTH + 2).'a'.\str_repeat('</B>', HtmlParser::MAX_DEPTH + 2), 'HTML labels nested deeper than 32'];
        yield 'deep tables' => [\str_repeat('<TABLE><TR><TD>', HtmlParser::MAX_DEPTH + 1).'a'.\str_repeat('</TD></TR></TABLE>', HtmlParser::MAX_DEPTH + 1), 'HTML labels nested deeper than 32'];
    }

    #[DataProvider('refusals')]
    public function testWhatItCannotDrawIsLeftToDot(string $html, string $reason): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('The native DOT renderer does not draw '.$reason);

        self::parse($html);
    }

    public function testSpansAreCappedAndAFontAroundTextIsText(): void
    {
        $table = self::parse('<TABLE><TR><TD COLSPAN="100000" ROWSPAN="x">a</TD></TR></TABLE>');
        $this->assertInstanceOf(HtmlTable::class, $table);
        $this->assertLessThan(HtmlParser::MAX_SPAN * 3, $table->size()[0], 'empty columns take only their spacing');

        $this->assertInstanceOf(HtmlText::class, self::parse('<B>not a table</B>'));
        $this->assertInstanceOf(HtmlText::class, self::parse('<B><I>text</I></B>'));
    }

    private static function parse(string $html): DotLabel
    {
        return HtmlParser::parse($html, new HtmlFont(14.0, '#000000'));
    }
}
