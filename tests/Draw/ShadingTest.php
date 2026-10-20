<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Color\ColorValue;
use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\GradientAxis;
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
        $this->assertSame(90.0, Units::turned($mark, 20.0)->gradientAngle, 'a gradient turns with the drawing');
    }

    public function testALinearGradientRunsAtItsAngleAcrossItsBox(): void
    {
        $box = [[144.0, 0.0], [198.0, 0.0], [198.0, 36.0], [144.0, 36.0]];

        // dot: 45 degrees on a 54 by 36 box from (151.91, 30.73) to (190.09, 5.27), y down.
        $this->assertEqualsWithDelta([[151.91, 30.73], [190.09, 5.27]], GradientAxis::ends($box, 45.0), 0.01);
        $this->assertEqualsWithDelta([[171.0, 36.0], [171.0, 0.0]], GradientAxis::ends($box, 90.0), 1e-9, 'up from the bottom');
        $ends = GradientAxis::ends($box, 0.0);
        $this->assertSame([0.0, 0.5, 1.0, 1.0], [
            GradientAxis::share($ends, null, 144.0, 3.0),
            GradientAxis::share($ends, null, 171.0, 30.0),
            GradientAxis::share($ends, null, 198.0, 0.0),
            GradientAxis::share($ends, null, 400.0, 0.0),
        ]);
        $this->assertSame([0.0, 1.0], [GradientAxis::share($ends, 0.3, 159.0, 0.0), GradientAxis::share($ends, 0.3, 161.0, 0.0)], 'all or nothing either side of a split');
        $this->assertSame(0.0, GradientAxis::share([[1.0, 1.0], [1.0, 1.0]], null, 5.0, 5.0), 'no way at all: the first colour');
    }

    public function testEveryBackendRunsAtAnAngleAndSplits(): void
    {
        $drawing = new Drawing(
            20.0,
            10.0,
            [new Mark(
                MarkKind::Polygon,
                [[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [0.0, 10.0]],
                stroke: 'none',
                strokeWidth: 0.0,
                gradient: ['#ff0000', '#0000ff'],
                gradientAngle: 90.0,
                gradientSplit: 0.3,
            )],
            '#ffffff',
        );

        $this->assertStringContainsString(
            'x1="10" y1="10" x2="10" y2="0"><stop offset="0.299" stop-color="#ff0000" stop-opacity="1"/><stop offset="0.3" stop-color="#0000ff"',
            Work::run(SvgBackend::render($drawing)),
        );
        $pdf = Work::run(new PdfBackend(compress: false)->render($drawing));
        $this->assertStringContainsString(
            '/Coords [10 10 10 0] /Function << /FunctionType 3 /Domain [0 1] /Functions [<< /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [1 0 0] /N 1 >> << /FunctionType 2 /Domain [0 1] /C0 [0 0 1] /C1 [0 0 1] /N 1 >>] /Bounds [0.3]',
            $pdf,
        );
        $png = \imagecreatefromstring(Work::run(new PngBackend()->render($drawing, dpi: 96)));
        $this->assertNotFalse($png);
        $middle = \intdiv(\imagesx($png), num2: 2);
        $this->assertSame([0xFF_0000, 0x00_00FF], [\imagecolorat($png, $middle, \imagesy($png) - 2) & 0xFF_FFFF, \imagecolorat($png, $middle, y: 2) & 0xFF_FFFF], 'red below the split, blue above');
    }

    public function testAnAngleMovesARadialGradientsFocus(): void
    {
        // dot: 45 degrees puts the focus at 85% across, 15% down.
        $this->assertEqualsWithDelta([0.8536, 0.1464], GradientAxis::focus(45.0), 1e-4);
        $this->assertSame([0.5, 0.5], GradientAxis::focus(0.0));
        $box = [0.0, 0.0, 20.0, 10.0];
        $centre = [0.5, 0.5];
        $this->assertSame(
            [0.0, 1.0, 1.0],
            [GradientAxis::radial($box, $centre, 10.0, 5.0), GradientAxis::radial($box, $centre, 25.0, 5.0), GradientAxis::radial($box, $centre, 10.0, 12.5)],
            'out to three quarters of the box',
        );
        $this->assertEqualsWithDelta(2 / 3, GradientAxis::radial($box, $centre, 20.0, 5.0), 1e-9, 'its side, two thirds of the way');
        $focus = GradientAxis::focus(90.0);
        $this->assertSame(0.0, GradientAxis::radial($box, $focus, 10.0, 0.0), 'the focus takes the first colour');
        $this->assertEqualsWithDelta(0.4, GradientAxis::radial($box, $focus, 10.0, 5.0), 1e-9, 'the centre: on the circle half its radius from the focus, 0.5 - 0.5t = 0.75t');

        $drawing = new Drawing(
            20.0,
            10.0,
            [new Mark(
                MarkKind::Polygon,
                [[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [0.0, 10.0]],
                stroke: 'none',
                strokeWidth: 0.0,
                gradient: ['#ffffff', '#0000ff'],
                shading: Shading::Radial,
                gradientAngle: 90.0,
            )],
            '#000000',
        );
        $this->assertStringContainsString('<radialGradient id="gradient-0" cx="50%" cy="50%" r="75%" fx="50%" fy="0%">', Work::run(SvgBackend::render($drawing)));
        $this->assertStringContainsString('/ShadingType 3 /ColorSpace /DeviceRGB /Coords [0 -0.6667 0 0 0 1]', Work::run(new PdfBackend(compress: false)->render($drawing)));
        $png = \imagecreatefromstring(Work::run(new PngBackend()->render($drawing, dpi: 96)));
        $this->assertNotFalse($png);
        $middle = \intdiv(\imagesx($png), num2: 2);
        $this->assertGreaterThan(\imagecolorat($png, $middle, \imagesy($png) - 2) & 0xFF_0000, \imagecolorat($png, $middle, y: 1) & 0xFF_0000, 'whiter at the top, where the focus is');
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
