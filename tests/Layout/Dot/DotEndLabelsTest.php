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
        // A node it overlaps by half a point does not count; by more, as dot 16.1.0 is seen to count it, it does.
        $this->assertSame([[30.0, 40.0, 50.0, 50.0]], DotEndLabels::placed($label, [[0.0, 49.5, 100.0, 100.0]], Direction::TopBottom));
        $this->assertSame([[50.0, 40.0, 70.0, 50.0]], DotEndLabels::placed($label, [[0.0, 49.45, 40.0, 100.0]], Direction::TopBottom));
    }

    public function testAnEndInsideACornerTakesItWithNothingElseToOverlap(): void
    {
        // The second label's end inside the first's corner above and left, and no obstacle at all.
        $labels = [[[50.0, 50.0], [20.0, 10.0]], [[45.0, 45.0], [4.0, 4.0]]];

        $this->assertSame([30.0, 50.0, 50.0, 60.0], DotEndLabels::placed($labels, [], Direction::TopBottom)[0]);
    }

    public function testTheCornersTurnWithTheLayout(): void
    {
        $label = [[[50.0, 50.0], [20.0, 10.0]]];

        $this->assertSame([[30.0, 50.0, 50.0, 60.0]], DotEndLabels::placed($label, [], Direction::BottomTop));
        $this->assertSame([[30.0, 50.0, 50.0, 60.0]], DotEndLabels::placed($label, [], Direction::LeftRight));
        $this->assertSame([[50.0, 50.0, 70.0, 60.0]], DotEndLabels::placed($label, [], Direction::RightLeft));
    }

    public function testLabelsAreSetInTheOrderGiven(): void
    {
        // dot 16.1.1, top-down, y up: n0 105.998x41.998 at (52.999, 116) made first, over n1
        // 68.998x58.997 at (52.999, 29.498); `n1 -> n0` written first, then `n0 -> n1`. Dot sets
        // n0's edge out first: its 10x10 tail label below and left of (47.121, 94.541), at
        // (42.121, 89.541); then n1's, whose 29x12 head label, under n0 at (58.877, 94.541), finds
        // that label below and left of it and goes below and right, at (73.377, 88.541).
        $nodes = [[0.0, -137.0, 106.0, -95.0], [18.5, -59.0, 87.5, 0.0]];
        $outOfN0 = [[[47.121, -94.541], [10.0, 10.0]], [[46.559, -59.385], [12.0, 15.0]]];
        $outOfN1 = [[[59.44, -59.385], [10.0, 10.0]], [[58.877, -94.541], [29.0, 12.0]]];
        $this->assertEqualsWithDelta(
            [[42.121, -89.541], [40.559, -66.885], [54.44, -64.385], [73.377, -88.541]],
            self::centres(DotEndLabels::placed([...$outOfN0, ...$outOfN1], $nodes, Direction::TopBottom)),
            0.01,
        );
        // Given in the order the edges were written, the head label would take below and left.
        $this->assertEqualsWithDelta(
            [44.377, -88.541],
            self::centres(DotEndLabels::placed([...$outOfN1, ...$outOfN0], $nodes, Direction::TopBottom))[1],
            0.01,
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

    public function testALabelGivenItsCentreStandsThereAndLaterOnesKeepClearOfIt(): void
    {
        // The first is set where given, over the node; the second, whose first corner it takes, goes below and left.
        $boxes = DotEndLabels::placed(
            [[[50.0, 50.0], [20.0, 10.0], [45.0, 45.0]], [[50.0, 50.0], [20.0, 10.0]]],
            [[40.0, 40.0, 60.0, 50.0]],
            Direction::TopBottom,
        );

        $this->assertSame(
            [
                [35.0, 40.0, 55.0, 50.0],
                [30.0, 50.0, 50.0, 60.0],
            ],
            $boxes,
        );
    }

    public function testPolarLabelsTurnFromTheirWayCounterClockwiseAsLaidOutTopDown(): void
    {
        // Leaving down the page, as measured on dot 16.1.0's output: turned 90 degrees, to the right; laid out bottom up, to the left.
        $ways = ['tail' => [[10.0, 20.0], [0.0, 1.0]]];

        $this->assertEqualsWithDelta(['tail' => [30.0, 20.0]], DotEndLabels::polar($ways, 90.0, 2.0, Direction::TopBottom), 1e-9);
        $this->assertEqualsWithDelta(['tail' => [-10.0, 20.0]], DotEndLabels::polar($ways, 90.0, 2.0, Direction::BottomTop), 1e-9);
        // The distance is held between 0 and 100.
        $this->assertEqualsWithDelta(['tail' => [10.0, 20.0]], DotEndLabels::polar($ways, 0.0, -3.0, Direction::RightLeft), 1e-9);
    }

    public function testAWayLeadsTowardTheCurveATenthOfTheWayAlongOrTheNextPoint(): void
    {
        $path = [[0.0, 0.0], [0.0, 0.0], [10.0, 0.0]];

        $this->assertSame([[0.0, 0.0], [1.0, 0.0]], DotEndLabels::way($path, []));
        // B(0.1) of (0,0) (0,10) (10,10) (10,0): (0.28, 2.7).
        [$from, [$x, $y]] = DotEndLabels::way($path, [[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [10.0, 0.0]]);
        $this->assertSame([0.0, 0.0], $from);
        $length = \hypot(0.28, y: 2.7);
        $this->assertEqualsWithDelta([0.28 / $length, 2.7 / $length], [$x, $y], 1e-9);
        // A curve starting elsewhere is not this path's; a path that does not move leads down.
        $this->assertSame([[0.0, 0.0], [1.0, 0.0]], DotEndLabels::way($path, [[5.0, 5.0], [0.0, 10.0], [10.0, 10.0], [10.0, 0.0]]));
        $this->assertSame([[0.0, 0.0], [0.0, 1.0]], DotEndLabels::way([[0.0, 0.0]], []));
        // A curve whose control points all stand at its end leaves no way: the path's own stands.
        $this->assertSame([[0.0, 0.0], [1.0, 0.0]], DotEndLabels::way($path, \array_fill(0, count: 4, value: [0.0, 0.0])));
        $this->assertSame([[0.0, 0.0], [0.0, 1.0]], DotEndLabels::way([[0.0, 0.0]], \array_fill(0, count: 4, value: [0.0, 0.0])));
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
