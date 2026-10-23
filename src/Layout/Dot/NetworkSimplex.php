<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The network simplex method for the layering problem of Gansner, Koutsofios, North and Vo,
 * "A Technique for Drawing Directed Graphs" (IEEE TSE, 1993): integer values for nodes 0…n-1
 * that keep value(head) - value(tail) ≥ minlen on every edge while minimising the sum of
 * weight × (value(head) - value(tail)). The graph must be connected and its constraints
 * acyclic. dot ranks nodes with it, and places them across each rank with it on an
 * auxiliary graph.
 *
 * It grows a tight spanning tree, then repeatedly swaps a tree edge whose cut value is
 * negative for the non-tree edge of least slack across the same cut, until no cut value is
 * negative. Each swap updates only what it changes: the cut values on the tree path between
 * the entering edge's ends, and the postorder numbering below their nearest common ancestor.
 * The values are then shifted to start at zero; balancing either spreads nodes over ranks of
 * equal cost or slides subtrees free to move to the middle of their range.
 */
final class NetworkSimplex
{
    public const int BALANCE_NONE = 0;
    /** Centre each free subtree between its constraints, as dot does across ranks. */
    public const int BALANCE_SIDES = 1;
    /** Spread nodes over the ranks they may take, as dot does down them. */
    public const int BALANCE_RANKS = 2;

    /** @var list<int> */
    private array $tails = [];
    /** @var list<int> */
    private array $heads = [];
    /** @var list<int> */
    private array $minlens = [];
    /** @var list<int> */
    private array $weights = [];
    /** @var list<list<int>> edges at each node, out and in */
    private array $adjacent = [];
    /** @var list<list<int>> edges leaving each node */
    private array $out = [];
    /** @var list<list<int>> edges entering each node */
    private array $in = [];
    /** @var list<int> */
    private array $value = [];
    /** @var list<bool> whether each edge is in the tree */
    private array $tree = [];
    /** @var list<int> the tree's edges, in a fixed order for the search for a leaving edge */
    private array $treeList = [];
    /** @var array<int, int> each tree edge's index in $treeList */
    private array $treeSlot = [];
    /** @var list<int> */
    private array $cut = [];
    /** @var list<int> each node's postorder number */
    private array $lim = [];
    /** @var list<int> the least postorder number in each node's subtree */
    private array $low = [];
    /** @var list<int> the tree edge to each node's parent, -1 at the root */
    private array $parent = [];
    /** @var list<int> the node with each postorder number, from 1 */
    private array $byLim = [];
    private int $search = 0;
    /** @var list<array<int, true>> the tree edges at each node */
    private array $treeAdjacent = [];
    /** @var list<bool> */
    private array $inTree = [];
    /** @var list<int> the tree's shift when each node joined it */
    private array $joined = [];
    private int $shift = 0;
    private int $size = 0;
    private int $sequence = \PHP_INT_MAX;
    private \SplPriorityQueue $outward;
    private \SplPriorityQueue $inward;

    /**
     * @param non-negative-int $nodes how many nodes, numbered from 0
     * @param list<array{int, int, int, int}> $edges tail, head, minlen, weight
     */
    public function __construct(
        private readonly int $nodes,
        array $edges,
        /** How many negative cut values to compare before swapping, as dot's `searchsize`. */
        private readonly int $searchSize = 30,
        /** How many swaps at most, or -1 for no limit, as dot's `nslimit` × nodes. */
        private readonly int $maxIterations = -1,
    ) {
        $this->adjacent = \array_fill(0, $nodes, []);
        $this->out = \array_fill(0, $nodes, []);
        $this->in = \array_fill(0, $nodes, []);
        foreach ($edges as $index => [$tail, $head, $minlen, $weight]) {
            $this->tails[] = $tail;
            $this->heads[] = $head;
            $this->minlens[] = $minlen;
            $this->weights[] = $weight;
            $this->out[$tail][] = $index;
            $this->in[$head][] = $index;
            $this->adjacent[$tail][] = $index;
            $this->adjacent[$head][] = $index;
        }
    }

    /**
     * @param self::BALANCE_* $balance
     * @return \Generator<int, null, mixed, list<int>>
     */
    public function solve(int $balance = self::BALANCE_NONE): \Generator
    {
        if (0 === $this->nodes) {
            return [];
        }
        $this->initialRanks();
        yield null;
        $this->feasibleTree();
        yield null;
        $this->initCutValues();
        $iterations = 0;
        while (-1 === $this->maxIterations || $iterations < $this->maxIterations) {
            $leave = $this->leaveEdge();
            if (null === $leave) {
                break;
            }
            $enter = $this->enterEdge($leave) ?? throw new \LogicException('Network simplex found no entering edge');
            $this->update($leave, $enter);
            if (0 === (++$iterations % 64)) {
                yield null;
            }
        }
        $this->normalize();
        if (self::BALANCE_RANKS === $balance) {
            $this->balanceRanks();
        }
        if (self::BALANCE_SIDES === $balance) {
            $this->balanceSides();
        }

        return $this->value;
    }

    /** Longest-path values: every node as low as its constraints allow, sources at zero. */
    private function initialRanks(): void
    {
        $this->value = \array_fill(0, $this->nodes, value: 0);
        $pending = \array_map(\count(...), $this->in);
        $queue = [];
        for ($node = 0; $node < $this->nodes; ++$node) {
            if (0 !== $pending[$node]) {
                continue;
            }
            $queue[] = $node;
        }
        for ($index = 0; $index < \count($queue); ++$index) {
            $node = $queue[$index];
            foreach ($this->out[$node] as $edge) {
                $head = $this->heads[$edge];
                $this->value[$head] = \max($this->value[$head], $this->value[$node] + $this->minlens[$edge]);
                if (0 === --$pending[$head]) {
                    $queue[] = $head;
                }
            }
        }
        if (\count($queue) !== $this->nodes) {
            throw new \InvalidArgumentException('Network simplex needs acyclic constraints');
        }
    }

    private function slack(int $edge): int
    {
        return $this->value[$this->heads[$edge]] - $this->value[$this->tails[$edge]] - $this->minlens[$edge];
    }

    /**
     * Grows a tree of tight edges from node 0; when it stops, shifts the whole tree by the least
     * slack of an edge leaving it, kept in two heaps whose keys a running shift offsets: edges
     * out of the tree lose slack as the tree rises, edges into it gain.
     */
    private function feasibleTree(): void
    {
        $this->tree = \array_fill(0, \count($this->tails), value: false);
        $this->treeList = [];
        $this->inTree = \array_fill(0, $this->nodes, value: false);
        $this->joined = \array_fill(0, $this->nodes, value: 0);
        $this->shift = 0;
        $this->size = 0;
        $this->outward = new \SplPriorityQueue();
        $this->inward = new \SplPriorityQueue();
        $this->outward->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->inward->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->grow(0);
        while ($this->size < $this->nodes) {
            [$best, $bestSlack, $bestOut] = $this->leastSlack();
            // Rising makes an outward edge tight; sinking an inward one.
            $this->shift += $bestOut ? $bestSlack : -$bestSlack;
            $this->tree[$best] = true;
            $this->treeList[] = $best;
            $this->grow($bestOut ? $this->heads[$best] : $this->tails[$best]);
        }
        for ($node = 0; $node < $this->nodes; ++$node) {
            $this->value[$node] += $this->shift - $this->joined[$node];
        }
        $this->treeSlot = \array_flip($this->treeList);
        $this->treeAdjacent = \array_fill(0, $this->nodes, []);
        foreach ($this->treeList as $edge) {
            $this->treeAdjacent[$this->tails[$edge]][$edge] = true;
            $this->treeAdjacent[$this->heads[$edge]][$edge] = true;
        }
    }

    private function current(int $node): int
    {
        return $this->inTree[$node] ? $this->value[$node] + $this->shift - $this->joined[$node] : $this->value[$node];
    }

    /** Adds a node to the tree, with every node tight edges reach from it, queueing the other edges they have. */
    private function grow(int $node): void
    {
        $this->inTree[$node] = true;
        $this->joined[$node] = $this->shift;
        ++$this->size;
        $stack = [$node];
        while ([] !== $stack) {
            $at = \array_pop($stack);
            foreach ($this->adjacent[$at] as $edge) {
                $tail = $this->tails[$edge];
                $head = $this->heads[$edge];
                $other = $tail === $at ? $head : $tail;
                if ($this->inTree[$other]) {
                    continue;
                }
                $slack = $this->current($head) - $this->current($tail) - $this->minlens[$edge];
                if (0 === $slack) {
                    $this->inTree[$other] = true;
                    $this->joined[$other] = $this->shift;
                    ++$this->size;
                    $this->tree[$edge] = true;
                    $this->treeList[] = $edge;
                    $stack[] = $other;
                    continue;
                }
                // The heaps pop their greatest priority: negated keys, a sequence keeping ties in order.
                if ($tail === $at) {
                    $this->outward->insert([$edge, $slack + $this->shift], [-($slack + $this->shift), $this->sequence--]);
                    continue;
                }
                $this->inward->insert([$edge, $slack - $this->shift], [-($slack - $this->shift), $this->sequence--]);
            }
        }
    }

    /**
     * The queued edge of least slack leaving the tree, dropping edges the tree has since swallowed.
     *
     * @return array{int, int, bool} the edge, its slack, and whether it leads out of the tree
     */
    private function leastSlack(): array
    {
        $best = null;
        $bestSlack = \PHP_INT_MAX;
        $bestOut = true;
        foreach ([true, false] as $isOut) {
            $heap = $isOut ? $this->outward : $this->inward;
            while (!$heap->isEmpty()) {
                /** @var array{data: array{int, int}, priority: array{int, int}} $top */
                $top = $heap->top();
                [$edge, $key] = $top['data'];
                if ($this->inTree[$this->tails[$edge]] && $this->inTree[$this->heads[$edge]]) {
                    $heap->extract();
                    continue;
                }
                $slack = $isOut ? $key - $this->shift : $key + $this->shift;
                if ($slack < $bestSlack) {
                    [$best, $bestSlack, $bestOut] = [$edge, $slack, $isOut];
                }
                break;
            }
        }
        if (null === $best) {
            throw new \InvalidArgumentException('Network simplex needs a connected graph');
        }
        ($bestOut ? $this->outward : $this->inward)->extract();

        return [$best, $bestSlack, $bestOut];
    }

    /**
     * Numbers the tree in postorder and computes every cut value. The weight crossing out of a
     * subtree, less the weight crossing in, is its children's sum plus the root's own edges
     * counted out as plus and in as minus; a tree edge's cut value is that flow seen from its
     * tail side.
     */
    private function initCutValues(): void
    {
        $this->parent = \array_fill(0, $this->nodes, -1);
        $this->lim = \array_fill(0, $this->nodes, value: 0);
        $this->low = \array_fill(0, $this->nodes, value: 0);
        $this->byLim = \array_fill(0, $this->nodes + 1, value: 0);
        $this->cut = \array_fill(0, \count($this->tails), value: 0);
        $order = $this->postorder(0, -1, 1);
        $flow = \array_fill(0, $this->nodes, value: 0);
        foreach ($order as $node) {
            foreach ($this->out[$node] as $edge) {
                $flow[$node] += $this->weights[$edge];
            }
            foreach ($this->in[$node] as $edge) {
                $flow[$node] -= $this->weights[$edge];
            }
            $edge = $this->parent[$node];
            if (-1 === $edge) {
                continue;
            }
            $parent = $this->tails[$edge] === $node ? $this->heads[$edge] : $this->tails[$edge];
            $flow[$parent] += $flow[$node];
            $this->cut[$edge] = $this->tails[$edge] === $node ? $flow[$node] : -$flow[$node];
        }
    }

    /**
     * Postorder numbering of a subtree from $root, reached by tree edge $edge, numbers starting
     * at $start; without recursion, walking tree edges only.
     *
     * @return list<int> the subtree's nodes in postorder
     */
    private function postorder(int $root, int $edge, int $start): array
    {
        $order = [];
        $next = $start;
        $this->parent[$root] = $edge;
        $nodes = [$root];
        $lows = [$start];
        $cursors = [0];
        $children = [\array_keys($this->treeAdjacent[$root])];
        $top = 0;
        while ($top >= 0) {
            $node = $nodes[$top];
            $list = $children[$top];
            $cursor = $cursors[$top];
            $count = \count($list);
            while ($cursor < $count && $list[$cursor] === $this->parent[$node]) {
                ++$cursor;
            }
            if ($cursor < $count) {
                $child = $list[$cursor];
                $cursors[$top] = $cursor + 1;
                $other = $this->tails[$child] === $node ? $this->heads[$child] : $this->tails[$child];
                $this->parent[$other] = $child;
                ++$top;
                $nodes[$top] = $other;
                $lows[$top] = $next;
                $cursors[$top] = 0;
                $children[$top] = \array_keys($this->treeAdjacent[$other]);
                continue;
            }
            $this->low[$node] = $lows[$top];
            $this->lim[$node] = $next;
            $this->byLim[$next] = $node;
            ++$next;
            $order[] = $node;
            --$top;
        }

        return $order;
    }

    /** A tree edge with a negative cut value, the most negative among the next few met in turn. */
    private function leaveEdge(): ?int
    {
        $count = \count($this->treeList);
        $best = null;
        $bestSlot = 0;
        $found = 0;
        for ($step = 0; $step < $count; ++$step) {
            $slot = ($this->search + $step) % $count;
            $edge = $this->treeList[$slot];
            if ($this->cut[$edge] >= 0) {
                continue;
            }
            if (null === $best || $this->cut[$edge] < $this->cut[$best]) {
                $best = $edge;
                $bestSlot = $slot;
            }
            if (++$found >= $this->searchSize) {
                break;
            }
        }
        if (null !== $best) {
            $this->search = $bestSlot;
        }

        return $best;
    }

    private function within(int $node, int $low, int $lim): bool
    {
        return $low <= $this->lim[$node] && $this->lim[$node] <= $lim;
    }

    /**
     * The non-tree edge of least slack crossing back over the cut a tree edge makes, searched
     * from whichever side of the cut is smaller.
     */
    private function enterEdge(int $leave): ?int
    {
        $tail = $this->tails[$leave];
        $head = $this->heads[$leave];
        // The subtree hangs below the end with the smaller postorder number.
        [$root, $outward] = $this->lim[$tail] < $this->lim[$head] ? [$tail, false] : [$head, true];
        $low = $this->low[$root];
        $lim = $this->lim[$root];
        $inside = ($lim - $low + 1) <= ($this->nodes - ($lim - $low + 1));
        $ranges = $inside ? [[$low, $lim]] : [[1, $low - 1], [$lim + 1, $this->nodes]];
        $best = null;
        $bestSlack = \PHP_INT_MAX;
        foreach ($ranges as [$from, $to]) {
            for ($number = $from; $number <= $to; ++$number) {
                $node = $this->byLim[$number];
                foreach ($this->adjacent[$node] as $edge) {
                    if ($this->tree[$edge]) {
                        continue;
                    }
                    $tailInside = $this->within($this->tails[$edge], $low, $lim);
                    $headInside = $this->within($this->heads[$edge], $low, $lim);
                    if ($outward ? !($tailInside && !$headInside) : !(!$tailInside && $headInside)) {
                        continue;
                    }
                    $slack = $this->slack($edge);
                    if ($slack < $bestSlack || ($slack === $bestSlack && null !== $best && $edge < $best)) {
                        $bestSlack = $slack;
                        $best = $edge;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Swaps the leaving edge for the entering one: shifts the subtree so the entering edge is
     * tight, moves the leaving edge's cut value along the tree path between the entering edge's
     * ends, and renumbers the subtree under their nearest common ancestor.
     */
    private function update(int $leave, int $enter): void
    {
        $delta = $this->slack($enter);
        if (0 !== $delta) {
            $tail = $this->tails[$leave];
            $head = $this->heads[$leave];
            $root = $this->lim[$tail] < $this->lim[$head] ? $tail : $head;
            $this->shiftSubtree($root, $this->within($this->tails[$enter], $this->low[$root], $this->lim[$root]) ? $delta : -$delta);
        }
        $cut = $this->cut[$leave];
        $ancestor = $this->pathUpdate($this->tails[$enter], $this->heads[$enter], $cut, forward: true);
        $this->pathUpdate($this->heads[$enter], $this->tails[$enter], $cut, forward: false);
        $this->cut[$enter] = -$cut;
        $this->cut[$leave] = 0;
        $this->tree[$leave] = false;
        $this->tree[$enter] = true;
        unset($this->treeAdjacent[$this->tails[$leave]][$leave], $this->treeAdjacent[$this->heads[$leave]][$leave]);
        $this->treeAdjacent[$this->tails[$enter]][$enter] = true;
        $this->treeAdjacent[$this->heads[$enter]][$enter] = true;
        $slot = $this->treeSlot[$leave];
        unset($this->treeSlot[$leave]);
        $this->treeList[$slot] = $enter;
        $this->treeSlot[$enter] = $slot;
        $this->postorder($ancestor, $this->parent[$ancestor], $this->low[$ancestor]);
    }

    /**
     * Walks up the tree from $from until reaching a node whose subtree holds $to, adding or
     * taking the cut value from each tree edge on the way, by its direction. Returns where it stopped.
     */
    private function pathUpdate(int $from, int $to, int $cut, bool $forward): int
    {
        $node = $from;
        while (!$this->within($to, $this->low[$node], $this->lim[$node])) {
            $edge = $this->parent[$node];
            $along = $node === $this->tails[$edge] ? $forward : !$forward;
            $this->cut[$edge] += $along ? $cut : -$cut;
            $node = $this->lim[$this->tails[$edge]] > $this->lim[$this->heads[$edge]] ? $this->tails[$edge] : $this->heads[$edge];
        }

        return $node;
    }

    private function shiftSubtree(int $root, int $amount): void
    {
        for ($number = $this->low[$root]; $number <= $this->lim[$root]; ++$number) {
            $this->value[$this->byLim[$number]] += $amount;
        }
    }

    private function normalize(): void
    {
        $minimum = \min($this->value);
        foreach ($this->value as $node => $value) {
            $this->value[$node] = $value - $minimum;
        }
    }

    /** Moves each node with as much weight in as out to the feasible rank holding the fewest nodes. */
    private function balanceRanks(): void
    {
        $counts = [];
        foreach ($this->value as $value) {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        $highest = \max(\array_keys($counts));
        for ($node = 0; $node < $this->nodes; ++$node) {
            $weightIn = 0;
            $weightOut = 0;
            $low = 0;
            $high = $highest;
            foreach ($this->in[$node] as $edge) {
                $weightIn += $this->weights[$edge];
                $low = \max($low, $this->value[$this->tails[$edge]] + $this->minlens[$edge]);
            }
            foreach ($this->out[$node] as $edge) {
                $weightOut += $this->weights[$edge];
                $high = \min($high, $this->value[$this->heads[$edge]] - $this->minlens[$edge]);
            }
            if ($weightIn !== $weightOut) {
                continue;
            }
            $best = $this->value[$node];
            for ($rank = $low; $rank <= $high; ++$rank) {
                $best = ($counts[$rank] ?? 0) < ($counts[$best] ?? 0) ? $rank : $best;
            }
            if ($best === $this->value[$node]) {
                continue;
            }
            --$counts[$this->value[$node]];
            $counts[$best] = ($counts[$best] ?? 0) + 1;
            $this->value[$node] = $best;
        }
    }

    /**
     * Where a tree edge's cut value is zero, its subtree can slide without cost as far as the
     * least slack of an edge back across that cut: it moves halfway, so nodes do not hug a side;
     * the subtrees lowest in the tree first, from the leaves up, as fits `dot`'s output best.
     */
    private function balanceSides(): void
    {
        $order = $this->treeList;
        // By the postorder number of the end the subtree hangs from.
        $key = fn (int $edge): int => \min($this->lim[$this->tails[$edge]], $this->lim[$this->heads[$edge]]);
        \usort($order, static fn (int $a, int $b): int => $key($a) <=> $key($b));
        foreach ($order as $edge) {
            if (0 !== $this->cut[$edge]) {
                continue;
            }
            $enter = $this->enterEdge($edge);
            if (null === $enter) {
                continue;
            }
            $delta = \intdiv($this->slack($enter), num2: 2);
            if ($delta < 1) {
                continue;
            }
            $tail = $this->tails[$edge];
            $head = $this->heads[$edge];
            $root = $this->lim[$tail] < $this->lim[$head] ? $tail : $head;
            $this->shiftSubtree($root, $this->within($this->tails[$enter], $this->low[$root], $this->lim[$root]) ? $delta : -$delta);
        }
    }
}
