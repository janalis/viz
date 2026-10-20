<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Orders the nodes of each rank to cross as few edges as it can, by the heuristic of Gansner
 * et al. Each connected component is ordered on its own, then the components stand side by
 * side in the order a depth-first search meets them. Within one, an initial order comes from
 * a breadth-first search from the nodes with nothing above, in that same visit order, or from
 * the nodes with nothing below; sweeps then sort each rank by the weighted median position
 * of its neighbours on the rank before, nodes with none staying put, each sweep followed by
 * transposing neighbours while that removes crossings. Two short passes from the two initial
 * orders, then a long one from the best order seen.
 *
 * Clusters stay contiguous: a scope, the top level or a cluster, orders its own nodes and one
 * block per child cluster and rank, then each child orders its block from inside, the nodes
 * around it fixed. A crossing costs the product of its two edges' penalties, so long virtual
 * stretches resist crossing more.
 */
final class DotOrdering
{
    /** @var list<int> each node's index in its rank */
    private array $position = [];
    private readonly DotCrossings $crossings;
    /** @var list<int> each node's component */
    private array $component = [];
    /** @var list<int> each node's place in its component's visit order */
    private array $visit = [];
    private int $active = 0;

    public function __construct(
        private readonly DotLayers $layers,
        private readonly int $maxIterations = 24,
        private readonly int $minQuit = 8,
        private readonly float $convergence = 0.995,
        /** Whether the layout is turned to run across, which reverses a cluster's own order, as dot does. */
        private readonly bool $turned = false,
    ) {
        $this->crossings = new DotCrossings($layers);
    }

    /** @return \Generator<int, null, mixed, void> */
    public function order(): \Generator
    {
        [$this->component, $this->visit, $components] = DotComponents::of($this->layers, $this->crossings);
        // Components stand side by side, each rank listing them in turn.
        foreach ($this->layers->ranks as $rank => $nodes) {
            \usort($nodes, fn (int $a, int $b): int => [$this->component[$a], $this->visit[$a]] <=> [$this->component[$b], $this->visit[$b]]);
            $this->layers->ranks[$rank] = $nodes;
        }
        $this->index();
        for ($component = 0; $component < $components; ++$component) {
            $this->active = $component;
            $work = $this->scope(-1);
            yield from $work;
        }
    }

    /** @return \Generator<int, null, mixed, void> */
    private function scope(int $scope): \Generator
    {
        $best = $this->layers->ranks;
        $bestCross = \PHP_INT_MAX;
        foreach ([Side::Above, Side::Below, null] as $start) {
            // Two short passes from the two initial orders, then a long one from the best seen.
            $this->layers->ranks = $best;
            $this->index();
            $cross = $bestCross;
            if (null !== $start) {
                $this->initial($scope, $start);
                $cross = $this->crossings->total($this->position);
                [$best, $bestCross] = $cross <= $bestCross ? [$this->layers->ranks, $cross] : [$best, $bestCross];
            }
            $limit = null === $start ? $this->maxIterations : \min(4, $this->maxIterations);
            $trying = 0;
            for ($iteration = 0; $iteration < $limit && $cross > 0 && $trying++ < $this->minQuit; ++$iteration) {
                $ties = ($iteration % 4) < 2 ? Ties::Exchange : Ties::Keep;
                $this->sweep($scope, 0 === ($iteration % 2) ? Side::Above : Side::Below, $ties);
                $this->transpose($scope, $ties->other());
                $cross = $this->crossings->total($this->position);
                if ($cross <= $bestCross) {
                    $trying = $cross < ($this->convergence * $bestCross) ? 0 : $trying;
                    [$best, $bestCross] = [$this->layers->ranks, $cross];
                }
                yield null;
            }
            if (0 === $cross) {
                break;
            }
        }
        $this->layers->ranks = $best;
        $this->index();
        if ($bestCross > 0) {
            $this->transpose($scope, Ties::Keep);
        }
        $this->consistent($scope);
        foreach ($this->children($scope) as $child) {
            if (!$this->holds($child)) {
                continue;
            }
            $work = $this->scope($child);
            yield from $work;
        }
    }

    /**
     * Gives the scope's child clusters one left-to-right order on every rank, by their mean
     * position, so that no two cluster boxes cross; plain nodes keep their slots.
     */
    private function consistent(int $scope): void
    {
        $ranks = \count($this->layers->ranks);
        $sum = [];
        $count = [];
        for ($rank = 0; $rank < $ranks; ++$rank) {
            foreach ($this->items($scope, $rank) as $slot => $item) {
                $child = $this->layers->childOf($item[0], $scope);
                if (-1 === $child) {
                    continue;
                }
                $sum[$child] = ($sum[$child] ?? 0) + $slot;
                $count[$child] = ($count[$child] ?? 0) + 1;
            }
        }
        if (\count($sum) < 2) {
            return;
        }
        $mean = [];
        foreach ($sum as $child => $total) {
            $mean[$child] = $total / $count[$child];
        }
        for ($rank = 0; $rank < $ranks; ++$rank) {
            $items = $this->items($scope, $rank);
            $slots = [];
            $blocks = [];
            foreach ($items as $slot => $item) {
                $child = $this->layers->childOf($item[0], $scope);
                if (-1 === $child) {
                    continue;
                }
                $slots[] = $slot;
                $blocks[] = [$mean[$child], $child, $item];
            }
            \usort($blocks, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            foreach ($slots as $index => $slot) {
                $items[$slot] = $blocks[$index][2];
            }
            $this->write($scope, $rank, $items);
        }
    }

    private function holds(int $cluster): bool
    {
        foreach ($this->layers->cluster as $node => $owner) {
            if ($this->component[$node] === $this->active && $this->layers->inside($node, $cluster)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    private function children(int $scope): array
    {
        return \array_keys(\array_filter($this->layers->parents, static fn (int $parent): bool => $parent === $scope));
    }

    /**
     * The scope's items on a rank, in order: its own nodes alone, and each child cluster as one
     * block of its nodes there, where its first node stands.
     *
     * @return list<list<int>>
     */
    private function items(int $scope, int $rank): array
    {
        $items = [];
        $blocks = [];
        foreach ($this->layers->ranks[$rank] as $node) {
            if ($this->component[$node] !== $this->active || !$this->layers->inside($node, $scope)) {
                continue;
            }
            $child = $this->layers->childOf($node, $scope);
            if (-1 === $child) {
                $items[] = [$node];
                continue;
            }
            if (!\array_key_exists($child, $blocks)) {
                $blocks[$child] = \count($items);
                $items[] = [];
            }
            $items[$blocks[$child]][] = $node;
        }

        return $items;
    }

    /**
     * Writes a scope's items back to their rank in a new order, into the slots the scope holds there.
     *
     * @param list<list<int>> $items
     */
    private function write(int $scope, int $rank, array $items): void
    {
        $nodes = \array_merge([], ...$items);
        $index = 0;
        foreach ($this->layers->ranks[$rank] as $slot => $node) {
            if ($this->component[$node] !== $this->active || !$this->layers->inside($node, $scope)) {
                continue;
            }
            $this->layers->ranks[$rank][$slot] = $nodes[$index];
            $this->position[$nodes[$index]] = $slot;
            ++$index;
        }
    }

    /**
     * An initial order: items installed in the order a breadth-first search meets them, started
     * from each item with nothing on the side it starts from, in visit order; the search runs
     * away from that side.
     */
    private function initial(int $scope, Side $from): void
    {
        $ranks = \count($this->layers->ranks);
        $itemsByRank = [];
        $itemOf = [];
        $seeds = [];
        for ($rank = 0; $rank < $ranks; ++$rank) {
            $itemsByRank[$rank] = $this->items($scope, $rank);
            foreach ($itemsByRank[$rank] as $index => $item) {
                foreach ($item as $node) {
                    $itemOf[$node] = [$rank, $index];
                }
                $seeds[] = [\min(\array_map(fn (int $node): int => $this->visit[$node], $item)), $rank, $index];
            }
        }
        \usort($seeds, static fn (array $a, array $b): int => $a <=> $b);
        $placed = [];
        $orders = \array_fill(0, \max(0, $ranks), []);
        foreach ($seeds as [, $rank, $index]) {
            if (\array_key_exists($rank.':'.$index, $placed) || $this->hasEdge($itemsByRank[$rank][$index], $from, $scope)) {
                continue;
            }
            $queue = [[$rank, $index]];
            $placed[$rank.':'.$index] = true;
            for ($head = 0; $head < \count($queue); ++$head) {
                [$itemRank, $itemIndex] = $queue[$head];
                $orders[$itemRank][] = $itemsByRank[$itemRank][$itemIndex];
                foreach ($itemsByRank[$itemRank][$itemIndex] as $node) {
                    foreach ($this->crossings->edges($node, $from->other()) as $edge) {
                        $other = $this->crossings->far($edge, $from->other());
                        if (!\array_key_exists($other, $itemOf)) {
                            continue;
                        }
                        $key = $itemOf[$other][0].':'.$itemOf[$other][1];
                        if (\array_key_exists($key, $placed)) {
                            continue;
                        }
                        $placed[$key] = true;
                        $queue[] = $itemOf[$other];
                    }
                }
            }
        }
        // The layered graph has no cycle: every item descends from a seed and has been placed.
        // As dot, a layout turned across lists the nodes of a cluster holding no other the other way.
        $reversed = $this->turned && $scope >= 0 && [] === $this->children($scope);
        foreach (\array_keys($itemsByRank) as $rank) {
            $this->write($scope, $rank, $reversed ? \array_reverse($orders[$rank]) : $orders[$rank]);
        }
    }

    /**
     * Whether an item has an edge on one side, other than one inside its own cluster block.
     *
     * @param list<int> $item
     */
    private function hasEdge(array $item, Side $side, int $scope): bool
    {
        foreach ($item as $node) {
            $child = $this->layers->childOf($node, $scope);
            foreach ($this->crossings->edges($node, $side) as $edge) {
                $other = $this->crossings->far($edge, $side);
                if ($this->layers->inside($other, $scope) && (-1 === $child || $this->layers->childOf($other, $scope) !== $child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sorts each rank, away from one side, by the weighted median of its items' neighbours on
     * that side: a bubble sort among the items with a median, the others fixed in place.
     */
    private function sweep(int $scope, Side $side, Ties $ties): void
    {
        $ranks = \array_keys($this->layers->ranks);
        // Every rank but the first met, whose neighbours on the side looked at are all fixed.
        $sequence = \array_slice(Side::Above === $side ? $ranks : \array_reverse($ranks), offset: 1);
        foreach ($sequence as $rank) {
            $items = $this->items($scope, $rank);
            $medians = \array_map(fn (array $item): float => $this->crossings->median($this->position, $item, $side), $items);
            $passes = \count($items);
            do {
                $moved = $this->bubble($items, $medians, $ties);
            } while ($moved && --$passes > 0);
            $this->write($scope, $rank, $items);
        }
    }

    /**
     * One pass of the bubble sort: each item with a median against the next one with a median.
     *
     * @param list<list<int>> $items
     * @param list<float> $medians
     * @return bool whether anything moved
     */
    private function bubble(array &$items, array &$medians, Ties $ties): bool
    {
        $count = \count($items);
        $changed = false;
        $left = 0;
        while ($left < $count && $medians[$left] < 0) {
            ++$left;
        }
        while ($left < $count) {
            $right = $left + 1;
            while ($right < $count && $medians[$right] < 0) {
                ++$right;
            }
            if ($right >= $count) {
                break;
            }
            if ($medians[$left] > $medians[$right] || (Ties::Exchange === $ties && $medians[$left] === $medians[$right])) {
                [$items[$left], $items[$right]] = [$items[$right], $items[$left]];
                [$medians[$left], $medians[$right]] = [$medians[$right], $medians[$left]];
                $changed = true;
            }
            $left = $right;
        }

        return $changed;
    }

    /** Swaps neighbouring items wherever that removes crossings, or keeps them equal when exchanging ties, until no swap helps. */
    private function transpose(int $scope, Ties $ties): void
    {
        $ranks = \count($this->layers->ranks);
        $improved = true;
        for ($passes = 0; $improved && $passes < 32; ++$passes) {
            $improved = false;
            for ($rank = 0; $rank < $ranks; ++$rank) {
                $items = $this->items($scope, $rank);
                for ($index = 0; ($index + 1) < \count($items); ++$index) {
                    $before = $this->crossings->pair($this->position, $items[$index], $items[$index + 1]);
                    $after = $this->crossings->pair($this->position, $items[$index + 1], $items[$index]);
                    if ($after > $before || ($after === $before && (Ties::Keep === $ties || 0 === $before))) {
                        continue;
                    }
                    [$items[$index], $items[$index + 1]] = [$items[$index + 1], $items[$index]];
                    $this->write($scope, $rank, $items);
                    $improved = $improved || $after < $before;
                }
            }
        }
    }

    private function index(): void
    {
        $this->position = \array_fill(0, \count($this->layers->rank), value: 0);
        foreach ($this->layers->ranks as $nodes) {
            foreach ($nodes as $slot => $node) {
                $this->position[$node] = $slot;
            }
        }
    }
}
