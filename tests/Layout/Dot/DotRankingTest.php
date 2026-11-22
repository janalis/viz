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

    public function testACycleBreaksAlongEdgesTakenInTheOrderOfTheirHeadsAsDotBreaksIt(): void
    {
        // Measured on dot 16.1.0, nodes declared n0, n3, n2: n0 → n2, n0 → n3, n3 → n2, n2 → n3
        // is drawn n0 | n3 | n2, the search from n0 meeting n3 before n2.
        $this->assertSame([0, 1, 2], self::rank(3, [[0, 2, 1, 1], [0, 1, 1, 1], [1, 2, 1, 1], [2, 1, 1, 1]]));
        // n0 … n5 with n1 ⇄ n4: dot draws n4 | n0 n1 | n2 n3 | (none) | n5, the search from n0 reaching
        // n4 by way of n2 and n5 before n1, so the edge n1 → n4 is the one reversed.
        $this->assertSame([1, 1, 2, 2, 0, 4], self::rank(6, [[0, 3, 1, 1], [4, 5, 1, 1], [0, 2, 1, 1], [1, 4, 1, 1], [4, 1, 1, 1], [2, 5, 2, 1], [1, 3, 1, 1]]));
    }

    public function testOverlappingRankSetsMergeAsDotMergesThem(): void
    {
        // Each as dot 16.1.0 draws it. a → b → c → d with {a c} and {c d}: one rank for a, c, d.
        $this->assertSame([0, 1, 0, 0], self::rank(4, [[0, 1, 1, 1], [1, 2, 1, 1], [2, 3, 1, 1]], sets: [[RankKind::Same, [0, 2]], [RankKind::Same, [2, 3]]]));
        // p → q (minlen 2), c → q, nodes p, q, c, x: a source set later merged into a same set
        // is held at the top but no longer alone there; merged into a source set, it is.
        $edges = [[0, 1, 2, 1], [2, 1, 1, 1]];
        $this->assertSame([0, 2, 0, 0], self::rank(4, $edges, sets: [[RankKind::Source, [2]], [RankKind::Same, [2, 3]]]));
        $this->assertSame([1, 3, 0, 0], self::rank(4, $edges, sets: [[RankKind::Same, [2, 3]], [RankKind::Source, [2]]]));
        $this->assertSame([0, 2, 0, 0], self::rank(4, $edges, sets: [[RankKind::Source, [2]], [RankKind::Min, [2, 3]]]));
        $this->assertSame([1, 3, 0, 0], self::rank(4, $edges, sets: [[RankKind::Min, [2]], [RankKind::Source, [2, 3]]]));
        // Every min set shares one rank: with x → p, c and x are drawn together above p.
        $this->assertSame([1, 3, 0, 0], self::rank(4, [...$edges, [3, 0, 1, 1]], sets: [[RankKind::Min, [2]], [RankKind::Min, [3]]]));
        $this->assertSame([1, 3, 0, 0], self::rank(4, [...$edges, [3, 0, 1, 1]], sets: [[RankKind::Source, [2]], [RankKind::Min, [3]]]));
        // a → b → c, y → x, nodes a, b, c, y, x: a set merged into one met later keeps its end.
        $chains = [[0, 1, 1, 1], [1, 2, 1, 1], [3, 4, 1, 1]];
        $this->assertSame([0, 1, 0, 1, 0], self::rank(5, $chains, sets: [[RankKind::Source, [2]], [RankKind::Same, [4, 2]], [RankKind::Same, []]]));
        $this->assertSame([1, 0, 1, 1, 0], self::rank(5, $chains, sets: [[RankKind::Sink, [0]], [RankKind::Same, [3, 0]]]));
        // A set held both at the top and at the bottom ties everything to one rank.
        $this->assertSame([0, 0, 0, 0, 0], self::rank(5, [[0, 1, 1, 1], [1, 2, 1, 1], [3, 4, 1, 1]], sets: [[RankKind::Min, [2]], [RankKind::Max, [2, 4]]]));
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
