<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where each node sits across its rank, by network simplex on the auxiliary graph of Gansner
 * et al. Neighbours keep the node separation, whatever clusters lie between them; the
 * outermost clusters two neighbours do not share keep a margin apart, and from the graph's
 * edge. Each layered edge pulls its ends level through a slack node, its weight times 8
 * between two virtual nodes, so long edges run straight, and 1 otherwise, as dot pulls them. Each cluster is bounded
 * by a node either side that holds its nodes, sub-clusters and label a margin inside.
 */
final class DotHorizontal
{
    /** How much harder a stretch between two virtual nodes pulls its ends level. */
    private const int STRAIGHT = 8;

    /** @var list<float> each node's x */
    public array $x = [];
    /** @var list<array{float, float}> each cluster's left and right */
    public array $boxes = [];
    public float $width = 0.0;

    /** @var list<array{int, int, int, int}> */
    private array $edges = [];
    /** @var non-negative-int */
    private int $count;
    /** @var ?array<int, int> each label node's original edge */
    private ?array $labelled = null;
    private readonly int $rootLeft;
    private readonly int $rootRight;

    /**
     * @param list<float> $labelWidths each cluster's label width
     * @param list<float> $bands each cluster's label band on its left, for a layout turned left to right, where that side becomes its top
     */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly array $labelWidths,
        private readonly float $nodeSeparation,
        private readonly float $padding,
        private readonly array $bands = [],
    ) {
        $nodes = \count($layers->rank) + (2 * \count($layers->parents));
        $this->rootLeft = $nodes;
        $this->rootRight = $nodes + 1;
        $this->count = $nodes + 2;
    }

    /** @return \Generator<int, null, mixed, void> */
    public function place(): \Generator
    {
        // The graph's bounds hold together, even with nothing between them.
        $this->edges = [[$this->rootLeft, $this->rootRight, 0, 0]];
        foreach ($this->layers->ranks as $nodes) {
            $this->separate($nodes);
        }
        // As the method's auxiliary graph has none, the graph has no bounds to hold its ranks: they
        // hang off one node, holding nothing, so that no rank's end, nor cluster, is held to them and
        // what is free to slide may settle midway.
        if ([] !== $this->layers->rank) {
            $this->edges[] = [$this->rootLeft, \array_key_first($this->layers->rank), 0, 0];
        }
        $this->contain();
        $this->straighten();
        // As `dot` is seen to, the subtrees holding real nodes are centred first, virtual nodes after.
        $movers = \array_map(static fn (): bool => true, \array_filter($this->layers->kind, static fn (int $kind): bool => DotLayers::REAL === $kind));
        $solved = new NetworkSimplex($this->count, $this->edges, movers: $movers)->solve(NetworkSimplex::BALANCE_SIDES);
        yield from $solved;
        $values = new DotCentring($this->layers, $this->room(...))->centred($solved->getReturn());
        // The graph's bounds hold what it draws, its clusters a margin inside, nothing pulling them in: they lie at its extremes.
        $lefts = [];
        $rights = [];
        foreach ($this->layers->rank as $node => $_) {
            $lefts[] = $values[$node] - $this->layers->left[$node];
            $rights[] = $values[$node] + $this->layers->right[$node];
        }
        foreach ($this->layers->parents as $cluster => $_) {
            $lefts[] = $values[$this->left($cluster)] - DotPosition::MARGIN;
            $rights[] = $values[$this->right($cluster)] + DotPosition::MARGIN;
        }
        $low = [] === $lefts ? $values[$this->rootLeft] : \min($lefts);
        $high = [] === $rights ? $values[$this->rootRight] : \max($rights);
        $shift = $this->padding - $low;
        foreach ($this->layers->rank as $node => $_) {
            $this->x[] = $values[$node] + $shift;
        }
        foreach ($this->layers->parents as $cluster => $_) {
            $this->boxes[] = [$values[$this->left($cluster)] + $shift, $values[$this->right($cluster)] + $shift];
        }
        $this->width = $high - $low + (2 * $this->padding);
    }

    private function room(int $previous, int $node): int
    {
        return self::length($this->layers->right[$previous] + $this->after($previous) + $this->gap($previous, $node) + $this->before($node) + $this->layers->left[$node]);
    }

    /** @param list<int> $nodes one rank, in order */
    private function separate(array $nodes): void
    {
        $layers = $this->layers;
        foreach ($nodes as $index => $node) {
            if (0 === $index) {
                continue;
            }
            $previous = $nodes[$index - 1];
            $this->edges[] = [$previous, $node, self::length($this->apart($previous, $node)), 0];
            $shared = $layers->common($layers->cluster[$previous], $layers->cluster[$node]);
            $from = $this->outermostRight($previous, $shared);
            $to = $this->outermostLeft($node, $shared);
            if ($from !== $previous || $to !== $node) {
                $gap = ($from === $previous ? $layers->right[$previous] : 0.0) + DotPosition::MARGIN + ($to === $node ? $layers->left[$node] : 0.0);
                $this->edges[] = [$from, $to, self::length($gap), 0];
            }
        }
    }

    /**
     * How far apart two neighbours stand, as `dot` is seen to set them: their sides, the room
     * each keeps, and the gap between; on a rank between nodes' ranks, the label nodes of two
     * edges joining the same two nodes, from the middle one of all such on the rank on, the
     * label between them and 4 — the last of them still keeping, from what follows, the room
     * the packed ones would have taken.
     */
    private function apart(int $left, int $right): float
    {
        $ends = 1 === ($this->layers->rank[$left] % 2) ? $this->ends($left) : null;
        if (null === $ends) {
            return $this->spaced($left, $right);
        }
        $group = $this->group($left, $ends);
        $index = (int) \array_search($left, $group, strict: true);
        if (($group[$index + 1] ?? null) === $right) {
            return $this->packs($group, $index) ? $this->packed($left, $right) : $this->spaced($left, $right);
        }
        $saved = 0.0;
        for ($pair = 0, $last = \count($group) - 1; $index === $last && $pair < $last; ++$pair) {
            $saved += $this->packs($group, $pair) ? $this->spaced($group[$pair], $group[$pair + 1]) - $this->packed($group[$pair], $group[$pair + 1]) : 0.0;
        }

        return $this->spaced($left, $right) + $saved;
    }

    private function spaced(int $left, int $right): float
    {
        $layers = $this->layers;

        return $layers->right[$left] + $this->after($left) + $this->gap($left, $right) + $this->before($right) + $layers->left[$right];
    }

    private function packed(int $left, int $right): float
    {
        return ($this->layers->mirrored ? $this->layers->left[$right] : $this->layers->right[$left]) + 4;
    }

    /**
     * Whether a label node and the next of its group pack, as `dot` is seen to pack those from
     * the middle one on.
     *
     * @param list<int> $group
     */
    private function packs(array $group, int $index): bool
    {
        return $index >= \intdiv(\count($group) - 1, num2: 2);
    }

    /**
     * The label nodes on a label node's rank whose edges join the same two nodes, in order.
     *
     * @param array{int, int} $ends
     * @return list<int>
     */
    private function group(int $node, array $ends): array
    {
        return \array_values(\array_filter($this->layers->ranks[$this->layers->rank[$node]], fn (int $other): bool => $this->ends($other) === $ends));
    }

    /**
     * The two nodes a label node's edge joins, lowest first; null for any other node.
     *
     * @return ?array{int, int}
     */
    private function ends(int $node): ?array
    {
        if (DotLayers::LABEL !== $this->layers->kind[$node]) {
            return null;
        }
        // A label node stands on its edge's path, which its layers make with it.
        $this->labelled ??= \array_flip($this->layers->labels);
        $path = $this->layers->paths[$this->labelled[$node]];
        [$first, $last] = [$path[0], $path[\count($path) - 1]];

        return [\min($first, $last), \max($first, $last)];
    }

    /**
     * The room between two neighbours, as `dot` is seen to keep it: the node separation, to the
     * nearest point; on the ranks a graph with edge labels puts between its nodes' ranks, 5;
     * where a labelled flat edge joins them, room for its label and a separation besides, two
     * separations at least.
     */
    private function gap(int $left, int $right): float
    {
        $separation = \round($this->nodeSeparation);
        $gap = [] !== $this->layers->labels && 1 === ($this->layers->rank[$left] % 2) ? 5.0 : $separation;
        foreach ($this->layers->flat as [$tail, $head, , $labelWidth, $labelHeight]) {
            if (($labelWidth <= 0 && $labelHeight <= 0) || ([$left, $right] !== [$tail, $head] && [$left, $right] !== [$head, $tail])) {
                continue;
            }
            $gap = \max($gap, 2 * $separation, $separation + $labelWidth);
        }

        return $gap;
    }

    /**
     * The room a node keeps on its left, besides its own width, as `dot` is seen to keep it: a
     * node of an edge's chain, half the node separation, rounded down to a point; a label node
     * with its label on its right, the node separation, to the point, its own width included,
     * whatever the label's size.
     */
    private function before(int $node): float
    {
        return match ($this->layers->kind[$node]) {
            DotLayers::VIRTUAL => \floor($this->nodeSeparation / 2),
            DotLayers::LABEL => $this->layers->mirrored ? 0.0 : \round($this->nodeSeparation) - $this->layers->left[$node],
            default => 0.0,
        };
    }

    /** The room a node keeps on its right, as on its left, a label node's label on its left in a mirrored layout. */
    private function after(int $node): float
    {
        return match ($this->layers->kind[$node]) {
            DotLayers::VIRTUAL => \floor($this->nodeSeparation / 2),
            DotLayers::LABEL => $this->layers->mirrored ? \round($this->nodeSeparation) - $this->layers->right[$node] : 0.0,
            default => 0.0,
        };
    }

    /**
     * The room a label node keeps inside its cluster on the side away from its label, as `dot` is
     * seen to: half a separation, rounded down, and its label's width, a whole separation at most.
     */
    private function held(int $node): float
    {
        if (DotLayers::LABEL !== $this->layers->kind[$node]) {
            return 0.0;
        }
        $label = $this->layers->mirrored ? $this->layers->left[$node] : $this->layers->right[$node];

        return \min(\round($this->nodeSeparation), \floor($this->nodeSeparation / 2) + $label) - ($this->layers->mirrored ? $this->layers->right[$node] : $this->layers->left[$node]);
    }

    /** Clusters hold their nodes, sub-clusters and label inside a margin, and hang off the graph's left bound. */
    private function contain(): void
    {
        foreach ($this->layers->cluster as $node => $cluster) {
            if (-1 === $cluster) {
                continue;
            }
            $this->edges[] = [
                $this->left($cluster),
                $node,
                self::length($this->layers->left[$node] + ($this->layers->mirrored ? 0.0 : $this->held($node)) + DotPosition::MARGIN + $this->band($cluster)),
                0,
            ];
            $this->edges[] = [$node, $this->right($cluster), self::length($this->layers->right[$node] + ($this->layers->mirrored ? $this->held($node) : 0.0) + DotPosition::MARGIN), 0];
        }
        foreach ($this->layers->parents as $cluster => $parent) {
            $this->edges[] = [$this->left($cluster), $this->right($cluster), self::length(\max($this->labelWidths[$cluster] + (2 * DotPosition::MARGIN), 2 * DotPosition::MARGIN)), 1];
            $this->edges[] = [$this->rootLeft, $this->left($cluster), 0, 0];
            if (-1 === $parent) {
                continue;
            }
            $this->edges[] = [$this->left($parent), $this->left($cluster), self::length(DotPosition::MARGIN + $this->band($parent)), 0];
            $this->edges[] = [$this->right($cluster), $this->right($parent), self::length(DotPosition::MARGIN), 0];
        }
    }

    /** Each layered edge, and each flat edge, pulls its ends level through a slack node of its own. */
    private function straighten(): void
    {
        foreach ($this->layers->edges as [$tail, $head, $weight, $omega, $tailPort, $headPort]) {
            $slack = $this->count++;
            // As dot, a stretch between two virtual nodes pulls hardest; one with a real end, as a plain edge.
            $pull = $omega >= self::STRAIGHT ? self::STRAIGHT : 1;
            // It pulls its ends' ports level, where they stand off their nodes' centres.
            $tailAt = $tailPort * 2 * $this->layers->left[$tail];
            $headAt = $headPort * 2 * $this->layers->left[$head];
            $most = \max($tailAt, $headAt);
            $this->edges[] = [$slack, $tail, self::length($most - $tailAt), $pull * $weight];
            $this->edges[] = [$slack, $head, self::length($most - $headAt), $pull * $weight];
        }
        foreach ($this->layers->flat as [$tail, $head]) {
            $slack = $this->count++;
            $this->edges[] = [$slack, $tail, 0, 1];
            $this->edges[] = [$slack, $head, 0, 1];
        }
    }

    /** The left bound of the outermost cluster around a node below a shared cluster, or the node itself. */
    private function outermostLeft(int $node, int $shared): int
    {
        $outermost = $this->outermost($node, $shared);

        return -1 === $outermost ? $node : $this->left($outermost);
    }

    /** The right bound of the outermost cluster around a node below a shared cluster, or the node itself. */
    private function outermostRight(int $node, int $shared): int
    {
        $outermost = $this->outermost($node, $shared);

        return -1 === $outermost ? $node : $this->right($outermost);
    }

    /** The outermost cluster around a node below a shared cluster, -1 for none. */
    private function outermost(int $node, int $shared): int
    {
        $outermost = -1;
        $cluster = $this->layers->cluster[$node];
        while ($cluster !== $shared && -1 !== $cluster) {
            $outermost = $cluster;
            $cluster = $this->layers->parents[$cluster];
        }

        return $outermost;
    }

    /** The room a cluster keeps on its left for its label, with a margin under it. */
    private function band(int $cluster): float
    {
        $band = $this->bands[$cluster] ?? 0.0;

        return $band > 0 ? $band + DotPosition::MARGIN : 0.0;
    }

    private function left(int $cluster): int
    {
        return \count($this->layers->rank) + (2 * $cluster);
    }

    private function right(int $cluster): int
    {
        return \count($this->layers->rank) + (2 * $cluster) + 1;
    }

    /** A length to the nearest point, half a point up, as `dot` is seen to keep gaps between nodes. */
    private static function length(float $value): int
    {
        return (int) \round($value);
    }
}
