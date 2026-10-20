<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\GraphViz\Drawing\DotFills;
use PHPUnit\Framework\TestCase;

final class DotFillsTest extends TestCase
{
    public function testStripesRunAcrossABoxInTheirShares(): void
    {
        $marks = DotFills::pattern(['striped' => true], 'Box', [10.0, 20.0, 110.0, 60.0], 'red:blue;0.2:green', '#000000');

        $this->assertSame(
            [
                [[10.0, 20.0], [50.0, 20.0], [50.0, 60.0], [10.0, 60.0]],
                [[50.0, 20.0], [70.0, 20.0], [70.0, 60.0], [50.0, 60.0]],
                [[70.0, 20.0], [110.0, 20.0], [110.0, 60.0], [70.0, 60.0]],
            ],
            \array_map(static fn (Mark $mark): array => $mark->points, $marks),
        );
        $this->assertSame(['#ff0000', '#0000ff', '#00ff00'], \array_map(static fn (Mark $mark): string => $mark->fill, $marks));
        $this->assertSame([0.5, '#000000'], [$marks[0]->strokeWidth, $marks[0]->stroke]);
    }

    public function testWedgesTurnUpFromAnEllipsesRight(): void
    {
        [$red, $blue] = DotFills::pattern(['wedged' => true], 'Ellipse', [100.0, 50.0, 140.0, 70.0], 'red;0.3:blue', '#333333');

        $this->assertSame([120.0, 60.0], $red->points[0], 'from the centre');
        $this->assertCount(1 + 22 + 1, $red->points, 'the centre, then 72 steps a turn, rounded up: 21.6 makes 22');
        $this->assertEqualsWithDelta([140.0, 60.0], $red->points[1], 1e-9, 'out to the right');
        $this->assertEqualsWithDelta([120.0 + (20 * \cos(0.6 * \M_PI)), 60.0 - (10 * \sin(0.6 * \M_PI))], $red->points[23], 1e-9, 'round to three tenths of a turn, upward');
        $this->assertCount(1 + 51 + 1, $blue->points, '50.4 steps make 51');
        $this->assertEqualsWithDelta([140.0, 60.0], $blue->points[52], 1e-9, 'all the way round');
        $this->assertSame([[], []], [
            DotFills::pattern(['wedged' => true], 'box', [0.0, 0.0, 1.0, 1.0], 'red', '#000000'),
            DotFills::pattern(['filled' => true], 'ellipse', [0.0, 0.0, 1.0, 1.0], 'red', '#000000'),
        ]);
    }
}
