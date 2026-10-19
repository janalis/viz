<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Layout\Dot\NetworkSimplex;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class NetworkSimplexTest extends TestCase
{
    public function testRandomGraphsReachTheOptimumABruteForceFinds(): void
    {
        for ($seed = 0; $seed < 120; ++$seed) {
            $random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
            $nodes = $random->getInt(2, 5);
            $edges = [];
            for ($node = 1; $node < $nodes; ++$node) {
                $edges[] = [$random->getInt(0, $node - 1), $node, $random->getInt(0, 2), $random->getInt(1, 5)];
            }
            for ($extra = $random->getInt(0, 3); $extra > 0; --$extra) {
                $tail = $random->getInt(0, $nodes - 2);
                $edges[] = [$tail, $random->getInt($tail + 1, $nodes - 1), $random->getInt(0, 2), $random->getInt(1, 5)];
            }
            $values = Work::run(new NetworkSimplex($nodes, $edges)->solve());

            $this->assertSame(0, \min($values), 'seed '.$seed);
            foreach ($edges as [$tail, $head, $minlen]) {
                $this->assertGreaterThanOrEqual($minlen, $values[$head] - $values[$tail], 'seed '.$seed);
            }
            $this->assertSame(self::best($nodes, $edges), self::cost($values, $edges), 'seed '.$seed);
        }
    }

    public function testBalancingSpreadsRanksAndCentresSides(): void
    {
        // a feeds b and c; d sits between with equal weight in and out, free on ranks 1 to 2.
        $ranks = Work::run(new NetworkSimplex(4, [[0, 1, 1, 1], [1, 2, 2, 1], [0, 3, 1, 1], [3, 2, 1, 1]])->solve(NetworkSimplex::BALANCE_RANKS));
        $this->assertSame([0, 1, 3, 2], $ranks, 'd leaves the crowded rank 1 for rank 2');

        // b hangs free between a and c, with nothing pulling it: balanced, it sits halfway.
        $sides = Work::run(new NetworkSimplex(3, [[0, 1, 2, 0], [1, 2, 2, 0], [0, 2, 10, 1]])->solve(NetworkSimplex::BALANCE_SIDES));
        $this->assertSame([0, 5, 10], $sides);
        $this->assertSame([], Work::run(new NetworkSimplex(0, [])->solve()));
    }

    public function testSwapsYieldAndALimitStopsThem(): void
    {
        // Rows of spaced nodes, pairs of them pulled together through slack nodes: many swaps.
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(1));
        $edges = [];
        for ($node = 1; $node < 300; ++$node) {
            if (0 === ($node % 15)) {
                continue;
            }
            $edges[] = [$node - 1, $node, 30, 0];
        }
        for ($slack = 300; $slack < 600; ++$slack) {
            $edges[] = [$slack, $random->getInt(0, 299), 0, 1];
            $edges[] = [$slack, $random->getInt(0, 299), 0, 1];
        }
        $steps = 0;
        foreach (new NetworkSimplex(600, $edges, searchSize: 1)->solve() as $_) {
            ++$steps;
        }
        $this->assertGreaterThan(2, $steps);
        $this->assertCount(600, Work::run(new NetworkSimplex(600, $edges, maxIterations: 1)->solve()));
    }

    public function testCyclesAndDisconnectedGraphsAreRefused(): void
    {
        try {
            Work::run(new NetworkSimplex(2, [[0, 1, 1, 1], [1, 0, 1, 1]])->solve());
            $this->fail('Expected a refusal');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertSame('Network simplex needs acyclic constraints', $refusal->getMessage());
        }
        $this->expectExceptionObject(new \InvalidArgumentException('Network simplex needs a connected graph'));

        Work::run(new NetworkSimplex(2, [])->solve());
    }

    /** @param list<array{int, int, int, int}> $edges */
    private static function best(int $nodes, array $edges): int
    {
        $best = \PHP_INT_MAX;
        $limit = \array_sum(\array_column($edges, 2)) + 1;
        $values = \array_fill(0, $nodes, value: 0);
        $search = static function (int $node) use (&$search, &$values, &$best, $nodes, $edges, $limit): void {
            if ($node === $nodes) {
                foreach ($edges as [$tail, $head, $minlen]) {
                    if (($values[$head] - $values[$tail]) < $minlen) {
                        return;
                    }
                }
                $best = \min($best, self::cost($values, $edges));

                return;
            }
            for ($value = 0; $value <= $limit; ++$value) {
                $values[$node] = $value;
                $search($node + 1);
            }
        };
        $search(0);

        return $best;
    }

    /**
     * @param list<int> $values
     * @param list<array{int, int, int, int}> $edges
     */
    private static function cost(array $values, array $edges): int
    {
        return \array_sum(\array_map(static fn (array $edge): int => $edge[3] * ($values[$edge[1]] - $values[$edge[0]]), $edges));
    }
}
