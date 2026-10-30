<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\DotEndLabels;
use Janalis\Viz\Model\Direction;
use PHPUnit\Framework\TestCase;

final class DotEndLabelsTest extends TestCase
{
    public function testALabelStandsOffItsEndsFirstClearCornerAsDotSetsIt(): void
    {
        $label = [[[50.0, 50.0], [20.0, 10.0]]];

        // As dot sets one alone, laid out top-down: above and left.
        $this->assertSame([[30.0, 40.0, 50.0, 50.0]], DotEndLabels::placed($label, [], Direction::TopBottom));
        // Where that is taken, below and left; then above and right.
        $this->assertSame([[30.0, 50.0, 50.0, 60.0]], DotEndLabels::placed($label, [[0.0, 0.0, 50.0, 50.0]], Direction::TopBottom));
        $this->assertSame([[50.0, 40.0, 70.0, 50.0]], DotEndLabels::placed($label, [[0.0, 0.0, 50.0, 100.0]], Direction::TopBottom));
        // Everywhere taken, where it overlaps least.
        $this->assertSame(
            [[50.0, 40.0, 70.0, 50.0]],
            DotEndLabels::placed(
                $label,
                [
                    [0.0, 0.0, 100.0, 100.0],
                    [0.0, 0.0, 60.0,  100.0],
                ],
                Direction::TopBottom,
            ),
        );
        // A node it only touches, within a point, does not count.
        $this->assertSame([[30.0, 40.0, 50.0, 50.0]], DotEndLabels::placed($label, [[0.0, 49.5, 100.0, 100.0]], Direction::TopBottom));
    }

    public function testTheCornersTurnWithTheLayout(): void
    {
        $label = [[[50.0, 50.0], [20.0, 10.0]]];

        $this->assertSame([[30.0, 50.0, 50.0, 60.0]], DotEndLabels::placed($label, [], Direction::BottomTop));
        $this->assertSame([[30.0, 50.0, 50.0, 60.0]], DotEndLabels::placed($label, [], Direction::LeftRight));
        $this->assertSame([[50.0, 50.0, 70.0, 60.0]], DotEndLabels::placed($label, [], Direction::RightLeft));
    }

    public function testLabelsAreSetLeftToRightTheHigherFirstAsLaidOutTopDown(): void
    {
        // Two ends level to the point: the higher's label set first takes its first corner, the other's the next.
        $labels = [[[50.4, 55.0], [20.0, 10.0]], [[50.0, 50.0], [20.0, 10.0]]];
        $this->assertSame(
            [
                [30.4, 55.0, 50.4, 65.0],
                [30.0, 40.0, 50.0, 50.0],
            ],
            DotEndLabels::placed($labels, [[50.0, 0.0, 100.0, 100.0]], Direction::TopBottom),
        );
        // Turned left to right: the higher first, then left to right; right to left, then right to left.
        $turned = [[[55.0, 50.0], [10.0, 10.0]], [[50.0, 50.0], [10.0, 10.0]]];
        $this->assertSame(
            [
                [55.0, 50.0, 65.0, 60.0],
                [40.0, 50.0, 50.0, 60.0],
            ],
            DotEndLabels::placed($turned, [], Direction::LeftRight),
        );
        $back = [[[70.0, 50.0], [10.0, 10.0]], [[65.0, 50.0], [10.0, 10.0]]];
        $this->assertSame(
            [
                [70.0, 50.0, 80.0, 60.0],
                [65.0, 40.0, 75.0, 50.0],
            ],
            DotEndLabels::placed($back, [], Direction::RightLeft),
        );
    }
}
