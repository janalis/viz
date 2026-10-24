<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\PlantUML\Draw\SvgPath;
use PHPUnit\Framework\TestCase;

final class SvgPathTest extends TestCase
{
    public function testLinesRelativeOrNotAndEachSubpathItsOwnOutline(): void
    {
        $this->assertSame(
            [[[0.0, 0.0], [10.0, 0.0], [10.0, 5.0]], [[20.0, 0.0], [25.0, 0.0], [25.0, -2.5]]],
            SvgPath::outlines('M0 0H10V5zm20 0h5v-2.5'),
        );
    }

    public function testNumbersRunTogetherAsSvgWritesThem(): void
    {
        $this->assertSame([[[1.0, -0.5], [0.25, 0.75], [-1.0, 0.75]]], SvgPath::outlines('M1-.5L.25.75-1 .75'));
    }

    public function testACurveEndsWhereItSays(): void
    {
        $outline = SvgPath::outlines('M0 0c1 0 2 1 2 2s1 2 2 2')[0];

        $this->assertSame([4.0, 4.0], $outline[\count($outline) - 1]);
        $this->assertCount(17, $outline);
    }

    public function testAQuadraticCurveAndItsSmoothFollowerMirrorTheirControl(): void
    {
        $outline = SvgPath::outlines('M0 0Q1 1 2 0T4 0')[0];

        // Half way along each: towards (1, 1), then towards its mirror, (3, -1).
        $this->assertEqualsWithDelta([[1.0, 0.5], [2.0, 0.0], [3.0, -0.5], [4.0, 0.0]], [$outline[4], $outline[8], $outline[12], $outline[16]], 1e-9);
    }

    public function testAnArcOfNoRadiusIsALineAndAnArcSweptTheOtherWayRunsBelow(): void
    {
        $this->assertSame([[[0.0, 0.0], [2.0, 0.0]]], SvgPath::outlines('M0 0a0 1 0 0 1 2 0'));
        $outline = SvgPath::outlines('M0 0a1 1 0 0 0 2 0')[0];
        // Swept counter-clockwise from the left of its centre, under it.
        $this->assertEqualsWithDelta([1.0, 1.0], $outline[4], 1e-9);
    }

    public function testAPathCutShortTakesWhatIsMissingAsNought(): void
    {
        $this->assertSame([[[1.0, 1.0], [0.0, 0.0]]], SvgPath::outlines('M1 1L'));
    }

    public function testAnArcWithFlagsRunTogetherReachesItsEndAroundItsCentre(): void
    {
        $outline = SvgPath::outlines('M0 0a1 1 0 012 0')[0];
        $middle = $outline[4];

        $this->assertSame([2.0, 0.0], $outline[\count($outline) - 1]);
        // Swept clockwise from the left of its centre, over the top.
        $this->assertEqualsWithDelta([1.0, -1.0], $middle, 1e-9);
    }
}
