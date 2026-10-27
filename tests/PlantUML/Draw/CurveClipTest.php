<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Draw\CurveClip;
use PHPUnit\Framework\TestCase;

final class CurveClipTest extends TestCase
{
    public function testACurveIsSplitWhereItLeavesEachBox(): void
    {
        $from = self::box(0.0);
        $to = self::box(100.0);
        $clipped = CurveClip::clipped([[0.0, 0.0], [0.0, 30.0], [0.0, 70.0], [0.0, 100.0]], $from, $to);

        $this->assertEqualsWithDelta([[0.0, 10.0], [0.0, 90.0]], [$clipped[0], $clipped[3]], 1e-4);
    }

    public function testACurveOutsideItsBoxesOrNeverLeavingThemIsKept(): void
    {
        $outside = [[0.0, 50.0], [0.0, 60.0], [0.0, 70.0], [0.0, 80.0]];
        $inside = [[0.0, 0.0], [0.0, 1.0], [0.0, 2.0], [0.0, 3.0]];
        $box = self::box(0.0);

        $this->assertSame([$outside, $inside, [[0.0, 0.0]]], [
            CurveClip::clipped($outside, $box, $box),
            CurveClip::clipped($inside, $box, $box),
            CurveClip::clipped([[0.0, 0.0]], $box, $box),
        ]);
    }

    private static function box(float $y): GraphNode
    {
        return new GraphNode(0, 'n', 'n', 0.0, $y, 20.0, 20.0, color: null, fillColor: null, dashed: false);
    }
}
