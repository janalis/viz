<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PdfBackend;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Draw\SvgBackend;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class SlotsTest extends TestCase
{
    public function testEachCharacterStandsInTheMiddleOfItsSlotAroundTheAnchor(): void
    {
        $mark = new Mark(MarkKind::Text, [[10.0, 4.0]], 'iWi', slots: [1.0, 4.0, 1.0]);

        $this->assertSame([-2.5, 0.0, 2.5], Mark::centres($mark));
        $this->assertNull(Mark::centres(new Mark(MarkKind::Text, [[0.0, 0.0]], 'ab')), 'no slots: as the font sets it');
        $this->assertNull(Mark::centres(new Mark(MarkKind::Text, [[0.0, 0.0]], 'ab', slots: [1.0])), 'slots that do not fit its characters');
        $this->assertNull(Mark::centres(new Mark(MarkKind::Text, [[0.0, 0.0]], "a\u{1F600}", slots: [1.0, 1.0])), 'a character SVG addresses as two');
        $this->assertSame([2.0, 8.0, 2.0], Units::scaled($mark, 2.0)->slots);
    }

    public function testAPolygonsOtherOutlinesAreFilledEvenOdd(): void
    {
        $ring = new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]], stroke: 'none', fill: '#000000', strokeWidth: 0.0, subpaths: [[
            [3.0, 3.0],
            [7.0, 3.0],
            [7.0, 7.0],
            [3.0, 7.0],
        ]]);
        $drawing = new Drawing(10.0, 10.0, [$ring], '#ffffff');

        $this->assertStringContainsString('fill-rule="evenodd"', Work::run(SvgBackend::render($drawing)));
        $this->assertNotSame(Work::run(new PdfBackend()->render(new Drawing(10.0, 10.0, [$ring->with(['subpaths' => []])], '#ffffff'))), Work::run(new PdfBackend()->render($drawing)));
        $image = \imagecreatefromstring(Work::run(new PngBackend()->render($drawing)));
        $this->assertNotFalse($image);
        $at = static fn (int $x, int $y): int => \imagecolorsforindex($image, \imagecolorat($image, $x, $y))['red'];
        $this->assertSame([0, 255], [$at(x: 15, y: 15), $at(x: 50, y: 50)], 'the ring filled, its hole not');
        $this->assertSame([[8.0, 8.0], [16.0, 8.0], [16.0, 16.0], [8.0, 16.0]], Units::moved(Units::scaled($ring, 2.0), 2.0, 2.0)->subpaths[0], 'moved and scaled with it');
    }

    public function testEveryFormatDrawsTheCharactersInTheirSlots(): void
    {
        $slotted = new Drawing(20.0, 6.0, [new Mark(MarkKind::Text, [[10.0, 4.0]], 'i W', stroke: '#000000', slots: [1.0, 1.0, 4.0])], '#ffffff');
        $plain = new Drawing(20.0, 6.0, [new Mark(MarkKind::Text, [[10.0, 4.0]], 'i W', stroke: '#000000')], '#ffffff');

        // A chunk for each character but the blank, in the middle of its slot.
        $svg = Work::run(SvgBackend::render($slotted));
        $this->assertStringContainsString('<tspan x="7.5">i</tspan><tspan x="11">W</tspan></text>', $svg);
        $this->assertNotSame(Work::run(new PngBackend()->render($plain)), Work::run(new PngBackend()->render($slotted)));
        $this->assertNotSame(Work::run(new PdfBackend()->render($plain)), Work::run(new PdfBackend()->render($slotted)));
    }
}
