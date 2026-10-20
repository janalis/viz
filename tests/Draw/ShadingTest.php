<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Color\ColorValue;
use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PdfBackend;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Draw\Shading;
use Janalis\Viz\Draw\SvgBackend;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class ShadingTest extends TestCase
{
    public function testColoursWithoutAlphaMixOpaque(): void
    {
        $this->assertSame('#808080ff', ColorValue::mixed('#ffffff', '#000000', 0.5));
        $this->assertSame('#ff000080', ColorValue::mixed('#ff000000', '#ff0000ff', 0.5));
    }

    public function testEveryBackendBlendsOutFromTheCentre(): void
    {
        $drawing = self::drawing(Shading::Radial);

        $this->assertStringContainsString('<radialGradient id="gradient-0" cx="50%" cy="50%" r="75%"><stop offset="0" stop-color="#ffffff"', Work::run(SvgBackend::render($drawing)));
        $pdf = Work::run(new PdfBackend(compress: false)->render($drawing));
        $this->assertStringContainsString('/ShadingType 3 /ColorSpace /DeviceRGB /Coords [0 0 0 0 0 1]', $pdf);
        $this->assertStringContainsString("15 0 0 7.5 10 5 cm\n", $pdf, 'a unit circle over three quarters of the box');
        $png = \imagecreatefromstring(Work::run(new PngBackend()->render($drawing, dpi: 96)));
        $this->assertNotFalse($png);
        [$width, $height] = [\imagesx($png), \imagesy($png)];
        $centre = \imagecolorat($png, \intdiv($width, num2: 2), \intdiv($height, num2: 2)) & 0xFF_FFFF;
        $edge = \imagecolorat($png, \intdiv($width, num2: 2), y: 2) & 0xFF_FFFF;
        $this->assertGreaterThan(0xF0_0000, $centre & 0xFF_0000, 'white at the centre');
        $this->assertLessThan($centre & 0xFF, $edge & 0xFF, 'reddening out to the edge');
    }

    public function testAPngGradientAcrossOfOpaqueColoursPaints(): void
    {
        $png = \imagecreatefromstring(Work::run(new PngBackend()->render(self::drawing(Shading::Linear), dpi: 96)));
        $this->assertNotFalse($png);
        $right = \imagecolorat($png, \imagesx($png) - 3, \intdiv(\imagesy($png), num2: 2));

        $this->assertSame(0, ($right >> 24) & 0x7F, 'opaque');
        $this->assertGreaterThan(0xC0, ($right >> 16) & 0xFF, 'red at the right');
        $this->assertLessThan(0x40, $right & 0xFF);
    }

    public function testUnitsKeepTheShading(): void
    {
        $mark = self::drawing(Shading::Radial)->marks[0];

        $this->assertSame(Shading::Radial, Units::scaled($mark, 2.0)->shading);
        $this->assertSame(Shading::Radial, Units::moved($mark, 1.0, 1.0)->shading);
        $this->assertSame(Shading::Radial, Units::turned($mark, 20.0)->shading);
    }

    private static function drawing(Shading $shading): Drawing
    {
        return new Drawing(
            20.0,
            10.0,
            [new Mark(MarkKind::Polygon, [[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [0.0, 10.0]], stroke: 'none', strokeWidth: 0.0, gradient: ['#ffffff', '#ff0000'], shading: $shading)],
            '#000000',
        );
    }
}
