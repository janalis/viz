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
                [55.0, 50.0, 65.0, 60.0],
            ],
            DotEndLabels::placed($back, [], Direction::RightLeft),
        );
    }

    public function testRightToLeftTheSecondCornerIsBelowAndLeft(): void
    {
        // dot 16.1.1, rankdir=RL: a 14x9 tail label ends 0.1 above its node, at (215.74, 105.91) y up;
        // below and right overlaps the node, above and right is clear, yet dot sets it below and
        // left, centred at (208.74, 101.41).
        $boxes = DotEndLabels::placed([[[215.74, -105.91], [14.0, 9.0]]], [[216.0, -105.81, 250.0, -52.81]], Direction::RightLeft);

        $this->assertEqualsWithDelta([[208.74, -101.41]], self::centres($boxes), 0.01);
    }

    public function testAnotherLabelsEndInsideACornerTakesIt(): void
    {
        // dot 16.1.1, top-down, y up: a 26x12 head label ending at (44.272, 89.706) under its
        // 95x29 node at (47.885, 104.5), between a 19x8 tail label set at (10.402, 85.546) off
        // (19.902, 89.546) and a 29x8 head label ending at (62.04, 89.551).
        $node = [[0.385, -119.0, 95.385, -90.0]];
        $tail = [[19.902, -89.546], [19.0, 8.0]];
        $head = [[44.272, -89.706], [26.0, 12.0]];
        $next = [[62.04, -89.551], [29.0, 8.0]];

        // Below and left overlaps the tail label; below and right holds the next label's end, and
        // so does the place a quarter right: dot centres it below its end, at (44.272, 83.706).
        $this->assertEqualsWithDelta(
            [[10.402, -85.546], [44.272, -83.706], [76.54, -85.551]],
            self::centres(DotEndLabels::placed([$tail, $head, $next], $node, Direction::TopBottom)),
            0.01,
        );
        // With no label at that end, dot sets it below and right, at (57.272, 83.706).
        $this->assertEqualsWithDelta(
            [[10.402, -85.546], [57.272, -83.706]],
            self::centres(DotEndLabels::placed([$tail, $head], $node, Direction::TopBottom)),
            0.01,
        );
    }

    public function testWithNoCornerClearALabelIsTriedBesideItsEnd(): void
    {
        // dot 16.1.1, rankdir=BT, y up: n1 -> n2 -> n3 in a column at x 140, n0 and n4 beside n1.
        $nodes = [
            [0.0, -49.0, 100.0, 0.0],
            [118.0, -35.5, 162.0, -13.5],
            [84.0, -134.0, 196.0, -85.0],
            [123.0, -197.01, 157.0, -170.01],
            [180.0, -40.0, 236.0, -9.0],
        ];
        $labels = [
            [[140.0, -35.679], [52.0, 26.0]],
            [[140.0, -84.664], [55.0, 24.0]],
            [[140.0, -134.48], [83.0, 13.0]],
            [[140.0, -169.74], [51.0, 19.0]],
        ];

        // The first tail label's corners all reach n1, n0 or n4: dot sets it above its end, a
        // quarter of its width right, at (153, 48.679); the others off their first clear corner.
        $this->assertEqualsWithDelta(
            [[153.0, -48.679], [112.5, -72.664], [98.5, -140.98], [114.5, -160.24]],
            self::centres(DotEndLabels::placed($labels, $nodes, Direction::BottomTop)),
            0.01,
        );
        // Laid out top-down, the places beside the end are below it: a quarter right, on it, a quarter left.
        $end = [[[50.0, 50.0], [20.0, 10.0]]];
        $corners = [
            [0.0,  0.0,  100.0, 50.0],
            [0.0,  50.0, 35.5,  100.0],
            [64.5, 50.0, 100.0, 100.0],
        ];
        $this->assertSame([[45.0, 50.0, 65.0, 60.0]], DotEndLabels::placed($end, $corners, Direction::TopBottom));
        $this->assertSame([[40.0, 50.0, 60.0, 60.0]], DotEndLabels::placed($end, [...$corners, [60.5, 50.0, 100.0, 100.0]], Direction::TopBottom));
        $this->assertSame([[35.0, 50.0, 55.0, 60.0]], DotEndLabels::placed($end, [...$corners, [55.5, 50.0, 100.0, 100.0]], Direction::TopBottom));
        // None clear either: the place that overlaps least, here the one on the end.
        $this->assertSame(
            [[40.0, 50.0, 60.0, 60.0]],
            DotEndLabels::placed(
                $end,
                [
                    [0.0,  0.0,  100.0, 50.0],
                    [0.0,  50.0, 42.0,  100.0],
                    [58.0, 50.0, 100.0, 100.0],
                ],
                Direction::TopBottom,
            ),
        );
    }

    /**
     * @param list<array{float, float, float, float}> $boxes
     * @return list<array{float, float}>
     */
    private static function centres(array $boxes): array
    {
        return \array_map(static fn (array $box): array => [($box[0] + $box[2]) / 2, ($box[1] + $box[3]) / 2], $boxes);
    }
}
