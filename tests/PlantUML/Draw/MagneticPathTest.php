<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Draw\MagneticPath;
use PHPUnit\Framework\TestCase;

final class MagneticPathTest extends TestCase
{
    public function testATabPushesAPointMeetingItsSideOrTheTopPastItsSlantBelowIt(): void
    {
        // A box 100 by 60 from the origin, its tab 30 wide and 20 tall.
        $box = new GraphNode(0, 'p', 'p', 50.0, 30.0, 100.0, 60.0, color: null, fillColor: null, dashed: false);
        $force = static fn (float $x, float $y): array => MagneticPath::force([30.0, 20.0], $box, [$x, $y]);

        // As `USymbolFolder`'s magnetic border: beside the tab, on the top past its slant, part way along the slant, nowhere else.
        $this->assertSame([[0.0, 20.0], [0.0, 20.0], [0.0, 10.0], [0.0, 0.0], [0.0, 0.0]], [
            $force(x: 100.0, y: 10.0),
            $force(x: 50.0, y: 0.0),
            $force(x: 30.0, y: -1.0),
            $force(x: 10.0, y: 0.0),
            $force(x: 100.0, y: 40.0),
        ]);
        $this->assertSame([0.0, 0.0], MagneticPath::force(null, $box, [100.0, 10.0]));
    }
}
