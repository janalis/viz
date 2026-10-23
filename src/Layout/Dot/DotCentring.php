<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where network simplex leaves a node or a block of nodes free to slide between equal pulls,
 * the place `dot` is seen to give it: midway between them, a node's chains down straight.
 */
final readonly class DotCentring
{
    /** @param \Closure(int, int): int $room the least room between two neighbours on a rank */
    public function __construct(
        private DotLayers $layers,
        private \Closure $room,
    ) {}

    /**
     * The positions with each real node free to slide between equal pulls set as `dot` is seen
     * to set it: the chains of virtual nodes it starts down straight from their far ends, and it
     * midway between its neighbours above and those ends; a block of source nodes held their least
     * room apart midway between the far ends they are pulled down to — where the ranks keep their
     * room. Left alone in graphs with clusters, flat edges or ports.
     *
     * @param list<int> $values
     * @return list<int>
     */
    public function centred(array $values): array
    {
        $layers = $this->layers;
        if ([] !== $layers->parents || [] !== $layers->flat) {
            return $values;
        }
        /** @var array<int, list<array{int, int}>> $up */
        $up = [];
        /** @var array<int, list<array{int, int}>> $down */
        $down = [];
        foreach ($layers->edges as [$tail, $head, $weight, , $tailPort, $headPort]) {
            if (0.0 !== $tailPort || 0.0 !== $headPort) {
                return $values;
            }
            $down[$tail][] = [$head, $weight];
            $up[$head][] = [$tail, $weight];
        }
        foreach ($layers->rank as $node => $_) {
            if (DotLayers::REAL !== $layers->kind[$node]) {
                continue;
            }
            // Pulled up by its neighbours as they stand, down by the far ends of the chains it starts.
            $anchors = [];
            $chains = [];
            foreach ($up[$node] ?? [] as [$next, $weight]) {
                $anchors[] = [$values[$next], $weight];
            }
            foreach ($down[$node] ?? [] as [$next, $weight]) {
                $found = $this->chain($next, $down, $values);
                if (null === $found) {
                    continue 2;
                }
                [$nodes, $end] = $found;
                $anchors[] = [$values[$end], $weight];
                $chains[] = [$nodes, $values[$end]];
            }
            $values = $this->midway($node, $anchors, $chains, $values);
        }
        foreach ($layers->ranks as $nodes) {
            foreach ($this->blocks($nodes, $values) as $block) {
                $values = $this->shifted($block, $up, $down, $values);
            }
        }

        return $values;
    }

    /**
     * The runs of real nodes on a rank each its least room from the next, which move as one.
     *
     * @param list<int> $nodes one rank, in order
     * @param list<int> $values
     * @return list<list<int>> those of two nodes or more
     */
    private function blocks(array $nodes, array $values): array
    {
        $blocks = [];
        $run = [];
        foreach ($nodes as $node) {
            $last = $run[\count($run) - 1] ?? null;
            if (DotLayers::REAL === $this->layers->kind[$node] && null !== $last && ($values[$node] - $values[$last]) === ($this->room)($last, $node)) {
                $run[] = $node;
                continue;
            }
            if (\count($run) > 1) {
                $blocks[] = $run;
            }
            $run = DotLayers::REAL === $this->layers->kind[$node] ? [$node] : [];
        }
        if (\count($run) > 1) {
            $blocks[] = $run;
        }

        return $blocks;
    }

    /**
     * A block of nodes moved as one midway between its pulls, each taken from where its node
     * stands in the block, where it is free to move and the ranks keep their room.
     *
     * @param list<int> $block
     * @param array<int, list<array{int, int}>> $up
     * @param array<int, list<array{int, int}>> $down
     * @param list<int> $values
     * @return list<int>
     */
    private function shifted(array $block, array $up, array $down, array $values): array
    {
        $first = $values[$block[0]];
        foreach ($block as $node) {
            if ([] !== ($up[$node] ?? [])) {
                return $values;
            }
        }
        $anchors = [];
        foreach ($block as $node) {
            $offset = $values[$node] - $first;
            foreach ($down[$node] ?? [] as [$next, $weight]) {
                $found = $this->chain($next, $down, $values, through: true);
                if (null === $found) {
                    return $values;
                }
                $anchors[] = [$values[$found[1]] - $offset, $weight];
            }
        }
        if (\count($anchors) < 2) {
            return $values;
        }
        [$low, $high] = self::median($anchors);
        if (($high - $low) < 2 || $first < $low || $first > $high) {
            return $values;
        }
        $delta = \intdiv($low + $high, num2: 2) - $first;
        $moved = $values;
        foreach ($block as $node) {
            $moved[$node] += $delta;
        }

        return $this->roomy($moved) ? $moved : $values;
    }

    /**
     * A chain down through virtual nodes free to move, to a real node or one its rank holds.
     *
     * @param array<int, list<array{int, int}>> $down
     * @param list<int> $values
     * @return ?array{list<int>, int} its free nodes and its end, or null where it forks
     */
    private function chain(int $next, array $down, array $values, bool $through = false): ?array
    {
        $nodes = [];
        while (DotLayers::REAL !== $this->layers->kind[$next] && ($through || !$this->pinned($next, $values))) {
            if (1 !== \count($down[$next] ?? [])) {
                return null;
            }
            $nodes[] = $next;
            $next = $down[$next][0][0];
        }

        return [$nodes, $next];
    }

    /**
     * A node moved midway between its pulls, with its chains straight from their ends, where it
     * is free to move and the ranks keep their room.
     *
     * @param list<array{int, int}> $anchors
     * @param list<array{list<int>, int}> $chains
     * @param list<int> $values
     * @return list<int>
     */
    private function midway(int $node, array $anchors, array $chains, array $values): array
    {
        if ([] === \array_filter($chains, static fn (array $chain): bool => [] !== $chain[0]) || \count($anchors) < 2) {
            return $values;
        }
        [$low, $high] = self::median($anchors);
        if (($high - $low) < 2 || $values[$node] < $low || $values[$node] > $high) {
            return $values;
        }
        $moved = $values;
        $moved[$node] = \intdiv($low + $high, num2: 2);
        foreach ($chains as [$nodes, $x]) {
            foreach ($nodes as $virtual) {
                $moved[$virtual] = $x;
            }
        }

        return $this->roomy($moved) ? $moved : $values;
    }

    /**
     * The interval of positions a weighted median takes between the anchors pulling a node.
     *
     * @param list<array{int, int}> $anchors each anchor's position and weight
     * @return array{int, int}
     */
    private static function median(array $anchors): array
    {
        \usort($anchors, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $total = \array_sum(\array_column($anchors, 1));
        $sum = 0;
        foreach ($anchors as $index => [$x, $weight]) {
            $sum += $weight;
            if ((2 * $sum) === $total) {
                return [$x, $anchors[$index + 1][0]];
            }
            if ((2 * $sum) > $total) {
                return [$x, $x];
            }
        }

        return [0, 0];
    }

    /** @param list<int> $values whether a node stands its least room from a neighbour on its rank */
    private function pinned(int $node, array $values): bool
    {
        $nodes = $this->layers->ranks[$this->layers->rank[$node]];
        $index = (int) \array_search($node, $nodes, strict: true);
        foreach ([$index - 1, $index + 1] as $other) {
            if (!\array_key_exists($other, $nodes)) {
                continue;
            }
            [$a, $b] = $other < $index ? [$nodes[$other], $node] : [$node, $nodes[$other]];
            if (($values[$b] - $values[$a]) <= ($this->room)($a, $b)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $values whether every rank keeps its neighbours their room */
    private function roomy(array $values): bool
    {
        $layers = $this->layers;
        foreach ($layers->ranks as $nodes) {
            foreach ($nodes as $index => $node) {
                if (0 === $index) {
                    continue;
                }
                $previous = $nodes[$index - 1];
                if (($values[$node] - $values[$previous]) < ($this->room)($previous, $node)) {
                    return false;
                }
            }
        }

        return true;
    }
}
