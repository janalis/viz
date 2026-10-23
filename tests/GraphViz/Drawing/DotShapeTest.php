<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Drawing\DotShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DotShapeTest extends TestCase
{
    /** @return iterable<string, array{string, int, int}> */
    public static function shapes(): iterable
    {
        foreach ([
            'box',
            'rect',
            'ellipse',
            'oval',
            'circle',
            'egg',
            'point',
            'diamond',
            'triangle',
            'invtriangle',
            'trapezium',
            'invtrapezium',
            'parallelogram',
            'house',
            'invhouse',
            'pentagon',
            'hexagon',
            'septagon',
            'octagon',
            'star',
            'cds',
            'polygon',
            'square',
        ] as $shape) {
            yield $shape => [$shape, 0, 0];
        }
        yield 'doublecircle' => ['doublecircle', 1, 0];
        yield 'doubleoctagon' => ['doubleoctagon', 1, 0];
        yield 'tripleoctagon' => ['tripleoctagon', 2, 0];
        yield 'Mcircle' => ['Mcircle', 0, 2];
        yield 'Mdiamond' => ['Mdiamond', 0, 4];
        yield 'Msquare' => ['Msquare', 0, 4];
        yield 'folder' => ['folder', 0, 0];
        foreach (['note', 'tab', 'cylinder', 'underline'] as $shape) {
            yield $shape => [$shape, 0, 1];
        }
        yield 'box3d' => ['box3d', 0, 2];
        yield 'component' => ['component', 0, 2];
    }

    #[DataProvider('shapes')]
    public function testEveryShapeHoldsItsCentreWithinItsBox(string $shape, int $rings, int $decorations): void
    {
        $outline = DotShape::of($shape, 80.0, 40.0);

        $this->assertCount($rings, $outline->peripheries);
        $this->assertCount($decorations, $outline->decorations);
        $this->assertTrue($outline->contains(0.0, 0.0));
        $this->assertFalse($outline->contains(41.0 + (4 * $rings), 0.0));
        // As `dot` draws them, a parallelogram leans past its box, a component's notches stand out 4 left and a tab's and a folder's 4 above.
        $reach = match ($shape) {
            'parallelogram' => 40.0 * 1.0735,
            'component' => 44.0,
            default => 40.0,
        };
        // A star, as big as covers its box, reaches past it.
        if ('star' === $shape) {
            return;
        }
        foreach ($outline->outline as [$x, $y]) {
            $this->assertLessThanOrEqual($reach + 1e-9, \abs($x));
            $this->assertLessThanOrEqual(\in_array($shape, ['tab', 'folder'], strict: true) ? 24.0 : 20.0 + 1e-9, \abs($y));
        }
    }

    public function testPeripheriesGrowOutwardAndTheOutermostClips(): void
    {
        $shape = DotShape::of('box', 20.0, 10.0, peripheries: 3);

        $this->assertCount(2, $shape->peripheries);
        $this->assertEqualsWithDelta([-18.0, -13.0], $shape->peripheries[1][0], 1e-9);
        $this->assertTrue($shape->contains(17.0, 0.0));
    }

    public function testAPolygonsOutlinesRunTheirDistanceOutsideAlongEverySide(): void
    {
        $shape = DotShape::of('diamond', 72.0, 36.0, peripheries: 2);

        // As `dot` draws them: the outer diamond's sides 4 outside the inner's, its corners where they meet.
        $this->assertEqualsWithDelta([[0.0, -22.47], [44.94, 0.0]], [$shape->peripheries[0][0], $shape->peripheries[0][1]], 0.01);
        $this->assertEqualsWithDelta([72.0, 36.0], DotShape::inner('diamond', 89.89, 44.94, 2), 0.01);
    }

    public function testRegularPolygonsReachTheirBoxAsDotDrawsThem(): void
    {
        $points = static fn (string $shape): array => \array_map(
            static fn (array $point): array => [\round($point[0], precision: 2), \round($point[1], precision: 2)],
            DotShape::of($shape, 144.0, 72.0)->outline,
        );

        // As `dot` draws them: a hexagon flat on top, a triangle's base a quarter of the way up, each reaching its box's sides.
        $this->assertSame([[36.0, -36.0], [72.0, 0.0], [36.0, 36.0], [-36.0, 36.0], [-72.0, 0.0], [-36.0, -36.0]], $points('hexagon'));
        $this->assertSame([[0.0, -36.0], [72.0, 18.0], [-72.0, 18.0]], $points('triangle'));
        $this->assertSame([[0.0, -36.0], [72.0, -11.12], [44.5, 29.12], [-44.5, 29.12], [-72.0, -11.12]], $points('pentagon'));
        // A star keeps its own proportions, as big as covers its box, from the box's bottom left corner.
        $this->assertSame([[0.0, -100.95], [17.0, -48.64], [72.0, -48.64]], \array_slice($points('star'), offset: 0, length: 3));
    }

    public function testPolygonsFollowSidesSkewDistortionAndOrientation(): void
    {
        $this->assertCount(3, DotShape::of('polygon', 20.0, 20.0, sides: 1)->outline);
        $this->assertCount(100, DotShape::of('polygon', 20.0, 20.0, sides: 500)->outline);
        $plain = DotShape::of('polygon', 20.0, 20.0, sides: 4)->outline;
        $skewed = DotShape::of('polygon', 20.0, 20.0, sides: 4, skew: 0.5)->outline;
        $this->assertGreaterThan($plain[0][0], $skewed[0][0], 'the top shifts right');
        $distorted = DotShape::of('polygon', 20.0, 20.0, sides: 4, distortion: 0.5)->outline;
        $this->assertGreaterThan(\abs($plain[0][0]), \abs($distorted[0][0]), 'the top widens');
        $this->assertNotEquals($plain, DotShape::of('polygon', 20.0, 20.0, sides: 4, orientation: 30.0)->outline);
        $this->assertSame(
            [[0.0, -0.0], [0.0, 0.0], [-0.0, 0.0], [-0.0, -0.0]],
            \array_map(static fn (array $point): array => [\round($point[0], precision: 6), \round($point[1], precision: 6)], DotShape::of('polygon', 0.0, 0.0)->outline),
        );
    }

    public function testDegenerateMarkedShapesDrawNoMarks(): void
    {
        $this->assertSame([], DotShape::of('Mdiamond', 0.0, 10.0)->decorations);
    }

    public function testGrowthAndRoundness(): void
    {
        $this->assertSame(\M_SQRT2, DotShape::growth('ellipse'));
        $this->assertSame(1.0, DotShape::growth('box'));
        $this->assertTrue(DotShape::round('circle'));
        $this->assertFalse(DotShape::round('ellipse'));
    }
}
