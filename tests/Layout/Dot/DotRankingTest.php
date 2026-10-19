<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\DotRanking;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotRankingTest extends TestCase
{
    public function testEdgesRankHeadsBelowTailsAndCyclesBreak(): void
    {
        // a → b → c → a: the edge closing the cycle is reversed.
        $this->assertSame([0, 1, 2], self::rank(3, [[0, 1, 1, 1], [1, 2, 1, 1], [2, 0, 1, 1]]));
        $this->assertSame([0, 2], self::rank(2, [[0, 1, 2, 1]]), 'minlen spaces ranks');
        $this->assertSame([0, 0], self::rank(2, []), 'every component starts at zero');
    }

    public function testSameRankSetsMergeAndExtremeSetsHoldTheirRank(): void
    {
        // a → b → c, with a and c on one rank: they merge, and the edge into c is reversed.
        $this->assertSame([0, 1, 0], self::rank(3, [[0, 1, 1, 1], [1, 2, 1, 1]], sets: [[RankKind::Same, [0, 2]]]));
        // c is a source: at the top, alone; b a sink: at the bottom, alone.
        $this->assertSame([1, 2, 0], self::rank(3, [[0, 1, 1, 1], [2, 0, 1, 1]], sets: [[RankKind::Source, [2]], [RankKind::Sink, [1]]]));
        // The edge into a min set points the other way.
        $this->assertSame([1, 0], self::rank(2, [[0, 1, 1, 1]], sets: [[RankKind::Min, [1]]]));
        $this->assertSame([0, 1, 1], self::rank(3, [[0, 2, 1, 1], [1, 0, 1, 1]], sets: [[RankKind::Max, [1, 2]]]));
    }

    public function testAClusterRanksOnItsOwnThenStandsInItsParent(): void
    {
        // x → y outside, cluster { a, b } with no edge inside; x → a and y → b put the cluster below y.
        $this->assertSame([0, 1, 2, 2], self::rank(4, [[0, 1, 1, 1], [0, 2, 1, 1], [1, 3, 1, 1]], [-1, -1, 0, 0], [-1]));
        // A nested cluster keeps its own ranks inside its parent.
        $this->assertSame([0, 1, 2], self::rank(3, [[0, 1, 1, 1], [1, 2, 1, 1]], [-1, 0, 1], [-1, 0]));
        // Between two clusters, an edge is a soft constraint that still orders them.
        $this->assertSame([1, 0], self::rank(2, [[1, 0, 1, 1]], [0, 1], [-1, -1]));
    }

    public function testEveryRankingStepYields(): void
    {
        $steps = 0;
        foreach (new DotRanking(2, [[0, 1, 1, 1]], [0, -1], [-1])->ranks() as $_) {
            ++$steps;
        }

        $this->assertGreaterThan(0, $steps);
    }

    /**
     * @param list<array{int, int, int, int}> $edges
     * @param ?list<int> $owner
     * @param list<int> $parents
     * @param list<array{RankKind, list<int>}> $sets
     * @return list<int>
     */
    private static function rank(int $nodes, array $edges, ?array $owner = null, array $parents = [], array $sets = []): array
    {
        return Work::run(
            new DotRanking(
                \max(0, $nodes),
                $edges,
                $owner ?? \array_fill(0, \max(0, $nodes), -1),
                $parents,
                $sets,
                [] === $parents ? \Janalis\Viz\Layout\Dot\NetworkSimplex::BALANCE_RANKS : 0,
            )->ranks(),
        );
    }
}
