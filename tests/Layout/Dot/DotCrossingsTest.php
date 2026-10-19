<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\DotCrossings;
use Janalis\Viz\Layout\Dot\DotLayers;
use Janalis\Viz\Layout\Dot\Side;
use Janalis\Viz\Layout\Dot\Ties;
use PHPUnit\Framework\TestCase;

final class DotCrossingsTest extends TestCase
{
    public function testMediansFollowGansnersRule(): void
    {
        // Rank 0: a, b, c, d, e; rank 1: x, y, z, w, v.
        $layers = new DotLayers(
            [0, 0, 0, 0, 0, 1, 1, 1, 1, 1],
            \array_fill(0, count: 10, value: [10.0, 10.0]),
            \array_fill(0, count: 10, value: -1),
            [],
            [[0, 5, 1, 0, 0], [1, 6, 1, 0, 0], [3, 6, 1, 0, 0], [0, 7, 1, 0, 0], [1, 7, 1, 0, 0], [2, 7, 1, 0, 0], [0, 8, 1, 0, 0], [1, 8, 1, 0, 0], [3, 8, 1, 0, 0], [4, 8, 1, 0, 0]],
        );
        $crossings = new DotCrossings($layers);
        $position = [0, 1, 2, 3, 4, 0, 1, 2, 3, 4];

        $this->assertSame(0.0, $crossings->median($position, [5], Side::Above), 'one neighbour');
        $this->assertSame(2.0, $crossings->median($position, [6], Side::Above), 'two: their mean');
        $this->assertSame(1.0, $crossings->median($position, [7], Side::Above), 'odd: the middle one');
        $this->assertSame(2.0, $crossings->median($position, [8], Side::Above), 'even: weighted toward the bunched side');
        $this->assertSame(-1.0, $crossings->median($position, [9], Side::Above), 'none');
        $this->assertSame(1.0, $crossings->median($position, [6, 7], Side::Above), 'an item of several nodes pools their neighbours');
    }

    public function testAnEvenMedianOfOneRepeatedPositionIsThatPosition(): void
    {
        // A block of four nodes, all under one node.
        $layers = new DotLayers([0, 1, 1, 1, 1], \array_fill(0, count: 5, value: [10.0, 10.0]), [-1, 0, 0, 0, 0], [-1], [[0, 1, 1, 0, 0], [0, 2, 1, 0, 0], [0, 3, 1, 0, 0], [0, 4, 1, 0, 0]]);

        $this->assertSame(0.0, new DotCrossings($layers)->median([0, 0, 1, 2, 3], [1, 2, 3, 4], Side::Above));
    }

    public function testCrossingsCostTheirPenaltiesMultiplied(): void
    {
        // a → y and b → x cross; a → y is a real edge, b → x passes a label node: 1 × 2.
        $layers = new DotLayers([0, 0, 1, 1], \array_fill(0, count: 4, value: [10.0, 10.0]), \array_fill(0, count: 4, value: -1), [], [[0, 3, 1, 0, 0], [1, 2, 1, 0, 0]]);
        $crossings = new DotCrossings($layers);

        $this->assertSame(1, $crossings->total([0, 1, 0, 1]));
        $this->assertSame(0, $crossings->total([0, 1, 1, 0]));
        $this->assertSame(1, $crossings->pair([0, 1, 0, 1], [0], [1]));
        $this->assertSame(0, $crossings->pair([0, 1, 0, 1], [1], [0]));
        $this->assertSame(Side::Below, Side::Above->other());
        $this->assertSame(Ties::Keep, Ties::Exchange->other());
    }
}
