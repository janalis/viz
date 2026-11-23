<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Orders the nodes of each rank to cross as few edges as it can, by the heuristic of Gansner
 * et al. Each connected component is ordered on its own, then the components stand side by
 * side in the order a depth-first search meets them. Within one, an initial order comes from
 * a breadth-first search from the nodes with nothing above, in that same visit order, or from
 * the nodes with nothing below, transposed once with no heed to flat edges, then put in the
 * order its flat edges keep, as `dot` is seen to begin; sweeps then sort each rank by the weighted median position
 * of its neighbours on the rank before, nodes with none staying put, each sweep followed by
 * transposing neighbours while that removes crossings. Two short passes from the two initial
 * orders, then a long one from the best order seen.
 *
 * Clusters stay contiguous: a scope, the top level or a cluster, orders its own nodes and one
 * block per child cluster and rank, then each child orders its block from inside, the nodes
 * around it fixed, from its initial order untransposed by the long pass alone. As `dot` reports
 * its passes, every component's top level is ordered first, then every cluster, outermost
 * first, then the whole graph once more by the long pass, each node moving only among the
 * nodes of its own cluster, only flat edges outside every cluster and `ordering` keeping an order. Until
 * it is ordered from inside, a cluster counts as one node on each of its ranks (see
 * {@see DotCrossings}). Every crossing counts one, whatever the edges' weights, as `dot` is
 * seen to count them where several orders are equally good, once for each edge between the
 * same two nodes.
 */
final class DotOrdering
{
    /** How many rounds transposition takes at most. */
    private const int ROUNDS = 32;

    /** @var list<int> each node's index in its rank */
    private array $position = [];
    private readonly DotSlots $slots;
    /** Whether the pass at hand orders the whole graph, after the clusters. */
    private bool $whole = false;
    private readonly DotCrossings $crossings;
    /** @var list<int> each node's component */
    private array $component = [];
    /** @var list<int> each node's place in its component's visit order */
    private array $visit = [];
    /** The component ordered, or -1 for all at once. */
    private int $active = 0;
    private readonly DotFlatOrder $flat;
    /** The order kept in the pass over the whole graph: flat edges outside every cluster, and `ordering` everywhere. */
    private readonly DotFlatOrder $outside;

    /**
     * @param list<int> $loose the edges, by their index in the layers, that do not constrain ranks, whose flat ones keep no order
     * @param list<array{int, int}> $kept pairs of nodes on one rank, the first kept before the second, as `ordering` asks
     */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly int $maxIterations = 24,
        private readonly int $minQuit = 8,
        private readonly float $convergence = 0.995,
        /** Whether the layout is turned to run across, which dot orders from the other end, as its order comes out mirrored. */
        private readonly bool $turned = false,
        array $loose = [],
        array $kept = [],
    ) {
        $this->crossings = new DotCrossings($layers);
        $this->slots = new DotSlots($layers);
        $this->flat = new DotFlatOrder($layers, $turned, $loose, $kept);
        $this->outside = new DotFlatOrder($layers, $turned, $loose, $kept, outside: true);
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
        // As `dot` reports its passes: every component's top level, then every cluster from
        // inside, then the whole graph once more.
        foreach ([$this->scope(...), $this->clusters(...)] as $step) {
            for ($component = 0; $component < $components; ++$component) {
                $this->active = $component;
                $work = $step(-1);
                yield from $work;
            }
        }
        if ($this->slots->opened()) {
            [$this->active, $this->whole] = [-1, true];
            $work = $this->scope(-1);
            yield from $work;
            $this->whole = false;
        }
        if ($this->turned) {
            foreach ($this->layers->ranks as $rank => $nodes) {
                $this->layers->ranks[$rank] = \array_reverse($nodes);
            }
            $this->index();
        }
    }

    /** @return \Generator<int, null, mixed, void> */
    private function scope(int $scope): \Generator
    {
        $this->index();
        $best = $this->layers->ranks;
        // The pass over the whole graph goes on from the order it is given, and counts from it.
        $bestCross = $this->whole ? $this->total() : \PHP_INT_MAX;
        // The top level: two short passes from the two initial orders, each transposed first, then
        // a long one from the best seen. A cluster, as `dot` reports its passes: its initial order,
        // transposed no time, then the long pass alone. The whole graph: the long pass alone.
        $short = \min(4, $this->maxIterations);
        $long = [null, $this->maxIterations, 0];
        $passes = $scope >= 0 ? [[Side::Above, 0, 0], $long] : [[Side::Above, $short, self::ROUNDS], [Side::Below, $short, self::ROUNDS], $long];
        $passes = $this->whole ? [$long] : $passes;
        foreach ($passes as [$start, $limit, $rounds]) {
            // The first pass starts from the order just counted.
            if ($this->layers->ranks !== $best) {
                $this->layers->ranks = $best;
                $this->index();
            }
            $cross = $bestCross;
            if (null !== $start) {
                $this->initial($scope, $start);
                // As `dot`'s reported crossings show, the initial order is transposed before its
                // flat edges put their ends in order, which may cross edges the transposition uncrossed.
                $this->transpose($scope, Ties::Keep, null, $rounds);
                $this->flatten($scope);
                $cross = $this->total();
                [$best, $bestCross] = $cross <= $bestCross ? [$this->layers->ranks, $cross] : [$best, $bestCross];
            }
            $trying = 0;
            for ($iteration = 0; $iteration < $limit && $cross > 0 && $trying++ < $this->minQuit; ++$iteration) {
                $ties = ($iteration % 4) < 2 ? Ties::Exchange : Ties::Keep;
                $this->sweep($scope, 0 === ($iteration % 2) ? Side::Above : Side::Below, $ties);
                $this->transpose($scope, $ties->other(), $this->flats());
                $cross = $this->total();
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
            $this->transpose($scope, Ties::Keep, $this->flats());
        }
        if (!$this->whole) {
            $this->consistent($scope);
        }
    }

    /**
     * Orders each child cluster of a scope from inside, then its own children, in turn.
     *
     * @return \Generator<int, null, mixed, void>
     */
    private function clusters(int $scope): \Generator
    {
        foreach (\array_filter($this->children($scope), $this->holds(...)) as $child) {
            $this->slots->open($child);
            $work = $this->scope($child);
            yield from $work;
            $work = $this->clusters($child);
            yield from $work;
        }
    }

    /** Every crossing, a cluster not yet ordered from inside counting as one node on each of its ranks. */
    private function total(): int
    {
        return $this->crossings->total($this->slots->slot, $this->slots->collapsed, $this->slots->blocks);
    }

    /** The order flat edges keep in the pass at hand. */
    private function flats(): DotFlatOrder
    {
        return $this->whole ? $this->outside : $this->flat;
    }

    /** Keeps the scope's child clusters from crossing one another, as {@see DotBlocks} orders them. */
    private function consistent(int $scope): void
    {
        $itemsByRank = \array_map(fn (int $rank): array => $this->items($scope, $rank), \array_keys($this->layers->ranks));
        $ordered = DotBlocks::consistent($itemsByRank, fn (int $node): int => $this->layers->childOf($node, $scope));
        // Only the ranks whose blocks moved are written back.
        $moved = \array_filter($ordered, static fn (array $items, int $rank): bool => $items !== $itemsByRank[$rank], \ARRAY_FILTER_USE_BOTH);
        \array_walk($moved, fn (array $items, int $rank) => $this->write($scope, $rank, $items));
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
            if (!$this->active($node) || !$this->layers->inside($node, $scope)) {
                continue;
            }
            // The pass over the whole graph moves every node on its own.
            $child = $this->whole ? -1 : $this->layers->childOf($node, $scope);
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
     * Swaps two neighbouring items: two nodes alone trade slots; anything larger is written back whole.
     *
     * @param list<list<int>> $items
     */
    private function swap(int $scope, int $rank, array &$items, int $index): void
    {
        [$left, $right] = [$items[$index], $items[$index + 1]];
        [$items[$index], $items[$index + 1]] = [$right, $left];
        if (1 !== \count($left) || 1 !== \count($right)) {
            $this->write($scope, $rank, $items);

            return;
        }
        [$a, $b] = [$left[0], $right[0]];
        [$slotA, $slotB] = [$this->position[$a], $this->position[$b]];
        $this->layers->ranks[$rank][$slotA] = $b;
        $this->layers->ranks[$rank][$slotB] = $a;
        [$this->position[$a], $this->position[$b]] = [$slotB, $slotA];
        $this->slots->swap($rank, $a, $b);
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
            if (!$this->active($node) || !$this->layers->inside($node, $scope)) {
                continue;
            }
            $this->layers->ranks[$rank][$slot] = $nodes[$index];
            $this->position[$nodes[$index]] = $slot;
            ++$index;
        }
        $this->slots->refresh($rank);
    }

    /** Whether a node is in the component being ordered. */
    private function active(int $node): bool
    {
        return -1 === $this->active || $this->component[$node] === $this->active;
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
        $blockAt = [];
        $seeds = [];
        for ($rank = 0; $rank < $ranks; ++$rank) {
            $itemsByRank[$rank] = $this->items($scope, $rank);
            foreach ($itemsByRank[$rank] as $index => $item) {
                foreach ($item as $node) {
                    $itemOf[$node] = [$rank, $index];
                }
                $blockAt[($this->slots->collapsed[$item[0]] ?? -1).':'.$rank] = [$rank, $index];
                $seeds[] = [...$this->seed($scope, $item), $rank, $index];
            }
        }
        \usort($seeds, static fn (array $a, array $b): int => $a <=> $b);
        $placed = [];
        $orders = \array_fill(0, \max(0, $ranks), []);
        foreach ($seeds as [, , $rank, $index]) {
            if (\array_key_exists($rank.':'.$index, $placed) || $this->hasEdge($itemsByRank[$rank][$index], $from, $scope)) {
                continue;
            }
            $queue = [[$rank, $index]];
            $placed[$rank.':'.$index] = true;
            for ($head = 0; $head < \count($queue); ++$head) {
                [$itemRank, $itemIndex] = $queue[$head];
                $orders[$itemRank][] = $itemsByRank[$itemRank][$itemIndex];
                $item = $itemsByRank[$itemRank][$itemIndex];
                // A cluster not yet ordered from inside goes on first along its own run to the
                // next rank; then the item's edges, all its nodes' together, in the order the
                // graph meets them, as `dot`'s reported crossings show.
                $edges = \array_merge([], ...\array_map(fn (int $node): array => $this->crossings->edges($node, $from->other()), $item));
                \sort($edges);
                $run = $blockAt[($this->slots->collapsed[$item[0]] ?? -2).':'.($itemRank + (Side::Above === $from ? 1 : -1))] ?? null;
                $next = [...(null === $run ? [] : [$run]), ...\array_map(fn (int $edge): ?array => $itemOf[$this->crossings->far($edge, $from->other())] ?? null, $edges)];
                foreach (\array_filter($next) as [$nextRank, $nextIndex]) {
                    if (\array_key_exists($nextRank.':'.$nextIndex, $placed)) {
                        continue;
                    }
                    $placed[$nextRank.':'.$nextIndex] = true;
                    $queue[] = [$nextRank, $nextIndex];
                }
            }
        }
        // The layered graph has no cycle: every item descends from a seed and has been placed.
        // As dot, a layout turned across starts each scope from the other end — but for a cluster
        // holding no other — and its order comes out mirrored, so such a cluster's own lies the other way.
        $reversed = $this->turned && ($scope < 0 || [] !== $this->children($scope));
        foreach (\array_keys($itemsByRank) as $rank) {
            $this->write($scope, $rank, $reversed ? \array_reverse($orders[$rank]) : $orders[$rank]);
        }
    }

    /** Puts each rank's items in an order every flat edge among them agrees with. */
    private function flatten(int $scope): void
    {
        $ranks = \array_keys($this->layers->ranks);
        \array_walk($ranks, fn (int $rank) => $this->write($scope, $rank, $this->flat->leftToRight($this->items($scope, $rank))));
    }

    /**
     * Where an item comes among the initial order's starts, as dot is seen to start them: the
     * top level from its nodes in the order a depth-first search meets them; a cluster from the
     * clusters it holds, in the order they were made, then its own nodes, in the order it holds them.
     *
     * @param list<int> $item
     * @return array{int, int}
     */
    private function seed(int $scope, array $item): array
    {
        if ($scope < 0) {
            return [0, \min(\array_map(fn (int $node): int => $this->visit[$node], $item))];
        }
        $child = $this->layers->childOf($item[0], $scope);

        return -1 === $child ? [1, $item[0]] : [0, $child];
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
            $medians = \array_map(fn (array $item): float => $this->crossings->median($this->slots->slot, $item, $side, $this->slots->collapsed, $this->slots->blocks), $items);
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
            $exchange = $medians[$left] > $medians[$right] || (Ties::Exchange === $ties && $medians[$left] === $medians[$right]);
            if ($exchange && !$this->held($items, $left, $right)) {
                [$items[$left], $items[$right]] = [$items[$right], $items[$left]];
                [$medians[$left], $medians[$right]] = [$medians[$right], $medians[$left]];
                $changed = true;
            }
            $left = $right;
        }

        return $changed;
    }

    /**
     * Whether a flat edge keeps the left item before the right one or any it would jump over,
     * as `dot`'s reported crossings show: one keeping a node it jumps over before the right
     * one holds nothing. In the pass over the whole graph, jumping over a node of another
     * cluster is held too.
     *
     * @param list<list<int>> $items
     */
    private function held(array $items, int $left, int $right): bool
    {
        for ($between = $left + 1; $between <= $right; ++$between) {
            if ($this->flats()->keeps($items[$left], $items[$between]) || $this->apart($items[$left], $items[$between])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swaps neighbouring items wherever that removes crossings, or keeps them equal when
     * exchanging ties, until no swap helps or the rounds run out; two items the flat order given
     * keeps stay as they are, and with none given, flat edges hold nothing.
     */
    private function transpose(int $scope, Ties $ties, ?DotFlatOrder $kept, int $rounds = self::ROUNDS): void
    {
        $ranks = \count($this->layers->ranks);
        // A rank's swaps depend on it and its neighbours alone: one none of them changed in
        // since it was last tried would swap nothing again, and is passed over.
        $changed = \array_fill(0, \max(1, $ranks), value: 0);
        $tried = \array_fill(0, \max(1, $ranks), value: -1);
        $clock = 0;
        $improved = true;
        for ($passes = 0; $improved && $passes < $rounds; ++$passes) {
            $improved = false;
            for ($rank = 0; $rank < $ranks; ++$rank) {
                if (\max($changed[$rank], $changed[$rank - 1] ?? 0, $changed[$rank + 1] ?? 0) < $tried[$rank]) {
                    continue;
                }
                $tried[$rank] = ++$clock;
                $items = $this->items($scope, $rank);
                for ($index = 0; ($index + 1) < \count($items); ++$index) {
                    [$before, $after] = $this->crossings->both($this->slots->slot, $items[$index], $items[$index + 1], $this->slots->collapsed, $this->slots->blocks);
                    if (
                        $after > $before
                        || ($after === $before && (Ties::Keep === $ties || 0 === $before))
                        || (null !== $kept && $kept->keeps($items[$index], $items[$index + 1]))
                        || $this->apart($items[$index], $items[$index + 1])
                    ) {
                        continue;
                    }
                    $this->swap($scope, $rank, $items, $index);
                    $changed[$rank] = ++$clock;
                    $improved = $improved || $after < $before;
                }
            }
        }
    }

    /**
     * Whether two items may not trade places: in the pass over the whole graph, nodes of
     * different clusters.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    private function apart(array $left, array $right): bool
    {
        return $this->whole && $this->layers->cluster[$left[0]] !== $this->layers->cluster[$right[0]];
    }

    private function index(): void
    {
        $this->position = \array_fill(0, \count($this->layers->rank), value: 0);
        foreach ($this->layers->ranks as $nodes) {
            foreach ($nodes as $slot => $node) {
                $this->position[$node] = $slot;
            }
        }
        $this->slots->index();
    }
}
