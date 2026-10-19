<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where each node sits across its rank, by network simplex on the auxiliary graph of Gansner
 * et al. Neighbours keep the node separation, whatever clusters lie between them; the
 * outermost clusters two neighbours do not share keep a margin apart, and from the graph's
 * edge. Each layered edge pulls its ends level through a slack node, its weight times 1, 2
 * or 8 as its ends are real or virtual, so long edges run straight. Each cluster is bounded
 * by a node either side that holds its nodes, sub-clusters and label a margin inside.
 */
final class DotHorizontal
{
    /** @var list<float> each node's x */
    public array $x = [];
    /** @var list<array{float, float}> each cluster's left and right */
    public array $boxes = [];
    public float $width = 0.0;

    /** @var list<array{int, int, int, int}> */
    private array $edges = [];
    /** @var non-negative-int */
    private int $count;
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
        $this->contain();
        $this->straighten();
        $solved = new NetworkSimplex($this->count, $this->edges)->solve(NetworkSimplex::BALANCE_SIDES);
        yield from $solved;
        $values = $solved->getReturn();
        $shift = $this->padding - $values[$this->rootLeft];
        foreach ($this->layers->rank as $node => $_) {
            $this->x[] = $values[$node] + $shift;
        }
        foreach ($this->layers->parents as $cluster => $_) {
            $this->boxes[] = [$values[$this->left($cluster)] + $shift, $values[$this->right($cluster)] + $shift];
        }
        $this->width = $values[$this->rootRight] - $values[$this->rootLeft] + (2 * $this->padding);
    }

    /** @param list<int> $nodes one rank, in order */
    private function separate(array $nodes): void
    {
        $layers = $this->layers;
        $last = \count($nodes) - 1;
        foreach ($nodes as $index => $node) {
            if (0 === $index) {
                $first = $this->outermostLeft($node, -1);
                $this->edges[] = [$this->rootLeft, $first, self::length($first === $node ? $layers->left[$node] : DotPosition::MARGIN), 0];
            }
            if ($index > 0) {
                $previous = $nodes[$index - 1];
                $this->edges[] = [$previous, $node, self::length($layers->right[$previous] + $this->nodeSeparation + $layers->left[$node]), 0];
                $shared = $layers->common($layers->cluster[$previous], $layers->cluster[$node]);
                $from = $this->outermostRight($previous, $shared);
                $to = $this->outermostLeft($node, $shared);
                if ($from !== $previous || $to !== $node) {
                    $gap = ($from === $previous ? $layers->right[$previous] : 0.0) + DotPosition::MARGIN + ($to === $node ? $layers->left[$node] : 0.0);
                    $this->edges[] = [$from, $to, self::length($gap), 0];
                }
            }
            if ($index === $last) {
                $end = $this->outermostRight($node, -1);
                $this->edges[] = [$end, $this->rootRight, self::length($end === $node ? $layers->right[$node] : DotPosition::MARGIN), 0];
            }
        }
    }

    /** Clusters hold their nodes, sub-clusters and label inside a margin, and sit inside the graph's bounds. */
    private function contain(): void
    {
        foreach ($this->layers->cluster as $node => $cluster) {
            if (-1 === $cluster) {
                continue;
            }
            $this->edges[] = [$this->left($cluster), $node, self::length($this->layers->left[$node] + DotPosition::MARGIN + $this->band($cluster)), 0];
            $this->edges[] = [$node, $this->right($cluster), self::length($this->layers->right[$node] + DotPosition::MARGIN), 0];
        }
        foreach ($this->layers->parents as $cluster => $parent) {
            $this->edges[] = [$this->left($cluster), $this->right($cluster), self::length(\max($this->labelWidths[$cluster] + (2 * DotPosition::MARGIN), 2 * DotPosition::MARGIN)), 1];
            $this->edges[] = [$this->rootLeft, $this->left($cluster), 0, 0];
            $this->edges[] = [$this->right($cluster), $this->rootRight, 0, 0];
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
        foreach ($this->layers->edges as [$tail, $head, $weight, $omega]) {
            $slack = $this->count++;
            $this->edges[] = [$slack, $tail, 0, $omega * $weight];
            $this->edges[] = [$slack, $head, 0, $omega * $weight];
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

    private static function length(float $value): int
    {
        return (int) \ceil($value);
    }
}
