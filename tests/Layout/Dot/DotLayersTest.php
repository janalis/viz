<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\Concentration;
use Janalis\Viz\Layout\Dot\DotLayers;
use PHPUnit\Framework\TestCase;

final class DotLayersTest extends TestCase
{
    public function testMergedChainsShareTrunksFromTheirTopAndToTheirBottom(): void
    {
        // Ranks: a 0; x, y 1; c 2; d 3; e 4. Edges: a → d, a → c, a → d again, x → e, y → e, e → a back up, a → e.
        $layers = self::layers(
            [0, 1, 1, 2, 3, 4],
            [[0, 4, 1, 0.0, 0.0], [0, 3, 1, 0.0, 0.0], [0, 4, 1, 0.0, 0.0], [1, 5, 1, 0.0, 0.0], [2, 5, 1, 0.0, 0.0], [5, 0, 1, 0.0, 0.0], [0, 5, 1, 0.0, 0.0]],
            Concentration::Merged,
        );
        [$toD, $toC, $again, $fromX, $fromY, $back, $toE] = [$layers->paths[0], $layers->paths[1], $layers->paths[2], $layers->paths[3], $layers->paths[4], $layers->paths[5], $layers->paths[6]];

        $this->assertSame($toD[1], $toC[1], 'a → c follows a → d\'s trunk');
        $this->assertSame($toD, $again, 'and the same edge again, the whole way');
        $this->assertSame([$toD[1], $toD[2]], [$toE[1], $toE[2]], 'a → e as far as a → d reaches');
        $this->assertSame(\array_slice($fromX, offset: 2), \array_slice($fromY, offset: 2), 'x → e and y → e share the way into e');
        $this->assertNotContains($toD[1], \array_slice($back, offset: 1, length: -1), 'an edge back up keeps apart');
        $weights = [];
        foreach ($layers->edges as [$tail, $head, $weight]) {
            $weights[$tail.'>'.$head] = $weight;
        }
        $this->assertSame(4, $weights['0>'.$toD[1]], 'a stretch weighs every edge along it');
    }

    public function testApartOrPortedChainsDoNotMerge(): void
    {
        $edges = [[0, 2, 1, 0.0, 0.0], [0, 3, 1, 0.0, 0.0]];
        $apart = self::layers([0, 1, 2, 3], $edges, Concentration::Apart);
        $ported = self::layers([0, 1, 2, 3], [[0, 2, 1, 0.0, 0.0, 0.2, 0.0], [0, 3, 1, 0.0, 0.0]], Concentration::Merged);

        $this->assertNotSame($apart->paths[0][1], $apart->paths[1][1]);
        $this->assertNotSame($ported->paths[0][1], $ported->paths[1][1], 'an edge with a port keeps its own way');
    }

    /**
     * @param list<int> $ranks
     * @param list<array{0: int, 1: int, 2: int, 3: float, 4: float, 5?: float, 6?: float}> $edges
     */
    private static function layers(array $ranks, array $edges, Concentration $concentration): DotLayers
    {
        $count = \count($ranks);

        return new DotLayers($ranks, \array_fill(0, $count, value: [10.0, 10.0]), \array_fill(0, $count, value: -1), [], $edges, 0.0, $concentration);
    }
}
