<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\Concentration;
use Janalis\Viz\Layout\Dot\DotCentring;
use Janalis\Viz\Layout\Dot\DotLayers;
use PHPUnit\Framework\TestCase;

final class DotCentringTest extends TestCase
{
    public function testANodeOrABlockWhoseChainForksStaysWhereItStands(): void
    {
        // Merged, a's edges to c and d and b's to c share one trunk, which forks below them: no straight chain to centre over.
        $layers = new DotLayers(
            [0, 0, 2, 2],
            \array_fill(0, count: 4, value: [10.0, 10.0]),
            [-1, -1, -1, -1],
            [],
            [[0, 2, 1, 0.0, 0.0], [0, 3, 1, 0.0, 0.0], [1, 2, 1, 0.0, 0.0]],
            concentration: Concentration::Merged,
        );
        $centring = new DotCentring($layers, static fn (int $left, int $right): int => 10);

        $this->assertSame([0, 100, 0, 200, 0], $centring->centred([0, 100, 0, 200, 0]), 'each node alone');
        $this->assertSame([0, 10, 0, 200, 0], $centring->centred([0, 10, 0, 200, 0]), 'the two sources a block, their least room apart');
    }
}
