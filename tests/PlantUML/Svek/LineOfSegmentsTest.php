<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Svek;

use Janalis\Viz\PlantUML\Svek\LineOfSegments;
use PHPUnit\Framework\TestCase;

final class LineOfSegmentsTest extends TestCase
{
    public function testSegmentsApartStayWhereTheyAre(): void
    {
        $this->assertSame([0.0, 20.0], LineOfSegments::solved([[0.0, 10.0], [20.0, 30.0]]));
        $this->assertSame([5.0], LineOfSegments::solved([[5.0, 9.0]]));
    }

    public function testOverlappingSegmentsArePushedApartAboutTheirMean(): void
    {
        // As PlantUML's LineOfSegments: the second pushed 5 on, both then moved back 2.5, their middles' mean kept.
        $this->assertSame([-2.5, 7.5], LineOfSegments::solved([[0.0, 10.0], [5.0, 15.0]]));
        // In order of their middles, whatever the order given; touching, they do not overlap.
        $this->assertSame([9.0, -1.0], LineOfSegments::solved([[8.0, 18.0], [0.0, 10.0]]));
        $this->assertSame([10.0, 0.0], LineOfSegments::solved([[10.0, 20.0], [0.0, 10.0]]));
        $this->assertEqualsWithDelta([-10.0, 0.0, 10.0], LineOfSegments::solved([[0.0, 10.0], [0.0, 10.0], [0.0, 10.0]]), 1e-9);
    }
}
