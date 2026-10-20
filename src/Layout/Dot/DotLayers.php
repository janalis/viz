<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The ranked graph mincross and positioning work on: every edge that spans ranks becomes a
 * chain of virtual nodes, one per rank between its ends, so each layered edge joins
 * neighbouring ranks; edges between the same two nodes share one chain. An edge with a
 * label gets a label node in the middle of its chain, as wide as the label. Edges whose
 * ends share a rank are flat, and an edge from a node to itself is a loop: neither joins
 * ranks.
 */
final class DotLayers
{
    public const int REAL = 0;
    public const int VIRTUAL = 1;
    public const int LABEL = 2;
    /** How far a loop reaches out to its node's right, before its label. */
    public const float LOOP = 18.0;

    /** @var list<int> each node's rank */
    public array $rank = [];
    /** @var list<int> REAL, VIRTUAL or LABEL */
    public array $kind = [];
    /** @var list<float> width left of each node's centre */
    public array $left = [];
    /** @var list<float> width right of each node's centre */
    public array $right = [];
    /** @var list<float> each node's height */
    public array $height = [];
    /** @var list<int> each node's innermost cluster, -1 at the top level */
    public array $cluster = [];
    /** @var list<array{int, int, int, int}> tail, head, weight, crossing penalty: every edge between neighbouring ranks, tail above */
    public array $edges = [];
    /** @var list<list<int>> the nodes of each rank, in order */
    public array $ranks = [];
    /** @var array<int, list<int>> each original edge's path through the ranks, tail to head as authored */
    public array $paths = [];
    /** @var array<int, int> the label node of each labelled original edge */
    public array $labels = [];
    /** @var list<array{int, int, int}> flat edges: tail, head, original edge */
    public array $flat = [];
    /** @var list<array{int, int}> loops: node, original edge */
    public array $loops = [];
    /** @var list<int> each cluster's parent, -1 at the top level */
    public array $parents = [];

    /**
     * @param list<int> $ranks each real node's rank
     * @param list<array{float, float}> $sizes each real node's width and height
     * @param list<int> $owner each real node's innermost cluster, -1 for none
     * @param list<int> $parents each cluster's parent, -1 for none
     * @param list<array{int, int, int, float, float}> $edges tail, head, weight, label width, label height, in authored order
     * @param float $spread how far apart edges sharing a chain run: each widens its virtual nodes by as much
     */
    public function __construct(
        array $ranks,
        array $sizes,
        array $owner,
        array $parents,
        array $edges,
        private readonly float $spread = 0.0,
    ) {
        $this->parents = $parents;
        foreach ($ranks as $node => $rank) {
            [$width, $height] = $sizes[$node];
            $this->add($rank, self::REAL, $width / 2, $width / 2, $height, $owner[$node]);
        }
        $chains = [];
        foreach ($edges as $index => [$tail, $head, $weight, $labelWidth, $labelHeight]) {
            if ($tail === $head) {
                // Loops are drawn to a node's right, one beside the other: the node is as wide as they reach.
                $this->loops[] = [$tail, $index];
                $this->right[$tail] += \max(self::LOOP, $this->height[$tail] / 2) + $labelWidth;
                continue;
            }
            if ($ranks[$tail] === $ranks[$head]) {
                $this->flat[] = [$tail, $head, $index];
                continue;
            }
            [$top, $bottom] = $ranks[$tail] < $ranks[$head] ? [$tail, $head] : [$head, $tail];
            $path =
                $labelWidth > 0 || $labelHeight > 0
                    ? $this->chain($top, $bottom, $weight, [$labelWidth, $labelHeight, $index])
                    : $this->shared($chains, $top, $bottom, $weight);
            $this->paths[$index] = $ranks[$tail] < $ranks[$head] ? $path : \array_reverse($path);
        }
    }

    /**
     * The chain between two nodes that unlabelled edges share: made by the first, its weight
     * raised by each one after.
     *
     * @param array<string, array{list<int>, list<int>}> $chains each shared chain's path and layered edges, by its ends
     * @return list<int> the path, top to bottom
     */
    private function shared(array &$chains, int $top, int $bottom, int $weight): array
    {
        $key = $top.'>'.$bottom;
        if (\array_key_exists($key, $chains)) {
            [$path, $layered] = $chains[$key];
            foreach ($layered as $edge) {
                $this->edges[$edge][2] += $weight;
            }
            foreach (\array_slice($path, offset: 1, length: -1) as $node) {
                $this->left[$node] += $this->spread / 2;
                $this->right[$node] += $this->spread / 2;
            }

            return $path;
        }
        $first = \count($this->edges);
        $path = $this->chain($top, $bottom, $weight, null);
        $chains[$key] = [$path, \range($first, \count($this->edges) - 1)];

        return $path;
    }

    /**
     * @param ?array{float, float, int} $label the label's width and height, and its edge
     * @return list<int> the path, top to bottom
     */
    private function chain(int $top, int $bottom, int $weight, ?array $label): array
    {
        $owner = $this->common($this->cluster[$top], $this->cluster[$bottom]);
        $path = [$top];
        $middle = \intdiv($this->rank[$top] + $this->rank[$bottom], num2: 2);
        for ($rank = $this->rank[$top] + 1; $rank < $this->rank[$bottom]; ++$rank) {
            $node =
                null !== $label && $rank === $middle
                    ? $this->add($rank, self::LABEL, 1.0, $label[0], $label[1], $owner)
                    : $this->add($rank, self::VIRTUAL, 1.0, 1.0, 1.0, $owner);
            if (null !== $label && $rank === $middle) {
                $this->labels[$label[2]] = $node;
            }
            $path[] = $node;
        }
        $path[] = $bottom;
        for ($index = 1, $count = \count($path); $index < $count; ++$index) {
            $tail = $path[$index - 1];
            $head = $path[$index];
            $virtual = (int) (self::REAL !== $this->kind[$tail]) + (int) (self::REAL !== $this->kind[$head]);
            // Crossing two virtual stretches costs more than crossing at a node: long edges stay straight.
            $this->edges[] = [
                $tail,
                $head,
                $weight,
                match ($virtual) {
                    2 => 8,
                    1 => 2,
                    default => 1,
                },
            ];
        }

        return $path;
    }

    private function add(int $rank, int $kind, float $left, float $right, float $height, int $cluster): int
    {
        $node = \count($this->rank);
        $this->rank[] = $rank;
        $this->kind[] = $kind;
        $this->left[] = $left;
        $this->right[] = $right;
        $this->height[] = $height;
        $this->cluster[] = $cluster;
        while (\count($this->ranks) <= $rank) {
            $this->ranks[] = [];
        }
        $this->ranks[$rank][] = $node;

        return $node;
    }

    /** The innermost cluster holding both, -1 for the top level. */
    public function common(int $a, int $b): int
    {
        $ancestors = [];
        for ($cluster = $a; -1 !== $cluster; $cluster = $this->parents[$cluster]) {
            $ancestors[$cluster] = true;
        }
        for ($cluster = $b; -1 !== $cluster; $cluster = $this->parents[$cluster]) {
            if (\array_key_exists($cluster, $ancestors)) {
                return $cluster;
            }
        }

        return -1;
    }

    /** Whether a node sits inside a cluster, at any depth. */
    public function inside(int $node, int $cluster): bool
    {
        for ($owner = $this->cluster[$node]; -1 !== $owner; $owner = $this->parents[$owner]) {
            if ($owner === $cluster) {
                return true;
            }
        }

        return -1 === $cluster;
    }

    /** The child of $scope that holds a node, or -1 when the node sits in $scope itself. */
    public function childOf(int $node, int $scope): int
    {
        $child = -1;
        for ($owner = $this->cluster[$node]; $owner !== $scope && -1 !== $owner; $owner = $this->parents[$owner]) {
            $child = $owner;
        }

        return $child;
    }
}
