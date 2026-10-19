<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use PHPUnit\Framework\TestCase;

final class BoxClipTest extends TestCase
{
    public function testAPathIsTrimmedAtBothBoxes(): void
    {
        $a = self::box(0.0, 0.0);
        $b = self::box(0.0, 100.0);

        $clipped = BoxClip::clipped([[0.0, 0.0], [0.0, 50.0], [0.0, 100.0]], $a, $b);
        $this->assertEqualsWithDelta([[0.0, 10.0], [0.0, 50.0], [0.0, 90.0]], $clipped, 0.01);
    }

    public function testAPathAlreadyOutsideOrWhollyInsideIsKept(): void
    {
        $a = self::box(0.0, 0.0);
        $this->assertSame([[50.0, 50.0], [60.0, 60.0]], BoxClip::clipped([[50.0, 50.0], [60.0, 60.0]], $a, self::box(500.0, 500.0)));
        $this->assertSame([[1.0, 1.0], [2.0, 2.0]], BoxClip::clipped([[1.0, 1.0], [2.0, 2.0]], $a, $a));
    }

    private static function box(float $x, float $y): GraphNode
    {
        return new GraphNode(0, 'n', '', $x, $y, 20.0, 20.0, null, null, dashed: false);
    }
}
