<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Drawing\DotStrokes;
use PHPUnit\Framework\TestCase;

final class DotStrokesTest extends TestCase
{
    /** Right 10, then down 10. */
    private const array BENT = [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]];

    public function testListsRunSideBySideSquareToTheirWay(): void
    {
        $lines = DotStrokes::lines(self::BENT, 'red:blue:green');
        $half = \sqrt(0.5) * 2;

        $this->assertSame(['#ff0000', '#0000ff', '#00ff00'], \array_column($lines, 1));
        $this->assertEqualsWithDelta([[0.0, 2.0], [10.0 - $half, $half], [8.0, 10.0]], $lines[0][0], 1e-9, 'the first 2 points to the left of the way down the screen');
        $this->assertSame(self::BENT, $lines[1][0], 'the middle one on the way');
        $this->assertEqualsWithDelta([[0.0, -2.0], [10.0 + $half, -$half], [12.0, 10.0]], $lines[2][0], 1e-9);
        $two = DotStrokes::lines(self::BENT, 'red: :blue');
        $this->assertEqualsWithDelta([[[0.0, 1.0], [10.0 - ($half / 2), $half / 2], [9.0, 10.0]], '#ff0000'], $two[0], 1e-9, 'two, a point either side; a blank colour passed over');
        $this->assertSame('#0000ff', $two[1][1]);
        $this->assertSame([[self::BENT, '#000000']], DotStrokes::lines(self::BENT, ''), 'none: black');
    }

    public function testWeightsCutTheWayIntoStretches(): void
    {
        $this->assertSame(
            [
                [[[0.0, 0.0], [5.0, 0.0]], '#ff0000'],
                [[[5.0, 0.0], [10.0, 0.0], [10.0, 5.0]], '#0000ff'],
                [[[10.0, 5.0], [10.0, 10.0]], '#00ff00'],
            ],
            DotStrokes::lines(self::BENT, 'red;0.25:blue:green;0.25'),
        );
        $this->assertSame(
            [[[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], '#0000ff']],
            DotStrokes::lines(self::BENT, 'red;0:blue;1:green'),
            'a colour weighing nothing draws nothing, nor one past the whole',
        );
        $this->assertCount(2, DotStrokes::lines(self::BENT, 'red;0.5;0.2:blue'), 'a malformed weight is none: side by side');
        $this->assertSame([['#ff0000', 0.0, 0.5], ['#0000ff', 0.5, 1.0]], DotStrokes::shares('red:blue'));
        $this->assertSame(
            [
                ['#ff0000', 0.0,  0.25],
                ['#0000ff', 0.25, 0.5],
            ],
            DotStrokes::shares('red;0.25:blue;0.25'),
            'what weights leave unclaimed stays empty',
        );
    }

    public function testArrowheadsTakeTheColourAtTheirEnd(): void
    {
        $this->assertSame(['#0000ff', '#ff0000'], DotStrokes::ends('red:blue'), 'side by side: the tail the last, the head the first');
        $this->assertSame(['#ff0000', '#0000ff'], DotStrokes::ends('red;0.5:blue'));
        $this->assertSame(['#000000', '#000000'], DotStrokes::ends(''));
    }
}
