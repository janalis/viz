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
    /** The room a loop keeps on its node's right, besides its label's, as `dot` is seen to keep it whatever its node separation. */
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
    /** @var list<array{int, int, int, int, float, float}> tail, head, weight, crossing penalty, and where across the tail and the head it ends (see {@see \Janalis\Viz\Layout\Engine\LayoutEdge::$tailPort}): every edge between neighbouring ranks, tail above */
    public array $edges = [];
    /** @var list<list<int>> the nodes of each rank, in order */
    public array $ranks = [];
    /** @var array<int, list<int>> each original edge's path through the ranks, tail to head as authored */
    public array $paths = [];
    /** @var array<int, int> the label node of each labelled original edge */
    public array $labels = [];
    /** @var list<array{int, int, int, float, float, bool}> flat edges: tail, head, original edge, its label's width and height, and whether only its ends' ranks make it flat */
    public array $flat = [];
    /** @var list<array{int, int}> loops: node, original edge */
    public array $loops = [];
    /** @var list<int> each cluster's parent, -1 at the top level */
    public array $parents = [];
    /** @var array<string, array<int, int>> merged chains' trunks, each its virtual node by rank, by the node they leave or reach and the way they run */
    private array $trunks = [];
    /** @var array<string, int> each stretch between neighbouring ranks, by its ends */
    private array $stretches = [];

    /**
     * @param list<int> $ranks each real node's rank
     * @param list<array{float, float}> $sizes each real node's width and height
     * @param list<int> $owner each real node's innermost cluster, -1 for none
     * @param list<int> $parents each cluster's parent, -1 for none
     * @param list<array{0: int, 1: int, 2: int, 3: float, 4: float, 5?: float, 6?: float, 7?: int}> $edges tail, head, weight, label width, label height, where across its tail and its head it ends, and its least length, in authored order
     * @param float $spread how much each edge after the first sharing a chain widens its virtual nodes
     * @param Concentration $concentration whether edges merge their chains, as dot's `concentrate`
     * @param bool $mirrored whether label nodes hold their label on their left, as `dot` is seen to set them in a layout to be turned left to right
     */
    public function __construct(
        array $ranks,
        array $sizes,
        array $owner,
        array $parents,
        array $edges,
        private readonly float $spread = 0.0,
        private readonly Concentration $concentration = Concentration::Apart,
        public readonly bool $mirrored = false,
    ) {
        $this->parents = $parents;
        foreach ($ranks as $node => $rank) {
            [$width, $height] = $sizes[$node];
            $this->add($rank, self::REAL, $width / 2, $width / 2, $height, $owner[$node]);
        }
        $chains = [];
        foreach ($edges as $index => $edge) {
            [$tail, $head, $weight, $labelWidth, $labelHeight] = $edge;
            if ($tail === $head) {
                // Loops are drawn to a node's right, one beside the other, or above it turned left to right, its left here: the node is as wide as the room they keep.
                $this->loops[] = [$tail, $index];
                $this->mirrored ? ($this->left[$tail] += self::LOOP + $labelWidth) : ($this->right[$tail] += self::LOOP + $labelWidth);
                continue;
            }
            if ($ranks[$tail] === $ranks[$head]) {
                // Whether it is flat for its ends' ranks alone, not for a length of 0.
                $this->flat[] = [$tail, $head, $index, $labelWidth, $labelHeight, ($edge[7] ?? 1) > 0];
                continue;
            }
            $down = $ranks[$tail] < $ranks[$head];
            [$top, $bottom] = $down ? [$tail, $head] : [$head, $tail];
            $ports = $down ? [$edge[5] ?? 0.0, $edge[6] ?? 0.0] : [$edge[6] ?? 0.0, $edge[5] ?? 0.0];
            $path = match (true) {
                $labelWidth > 0 || $labelHeight > 0 => $this->chain($top, $bottom, $weight, $ports, [$labelWidth, $labelHeight, $index]),
                Concentration::Merged === $this->concentration && [0.0, 0.0] === $ports => $this->merged($top, $bottom, $weight, $down ? 'down' : 'up'),
                default => $this->shared($chains, $top, $bottom, $weight, $ports),
            };
            $this->paths[$index] = $ranks[$tail] < $ranks[$head] ? $path : \array_reverse($path);
        }
    }

    /**
     * The chain between two nodes that unlabelled edges share: made by the first, its weight
     * raised by each one after.
     *
     * @param array<string, array{list<int>, list<int>}> $chains each shared chain's path and layered edges, by its ends and ports
     * @param array{float, float} $ports where across its top and its bottom node it ends: edges ending elsewhere share no chain
     * @return list<int> the path, top to bottom
     */
    private function shared(array &$chains, int $top, int $bottom, int $weight, array $ports): array
    {
        $key = $top.'>'.$bottom.'@'.\implode(',', $ports);
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
        $path = $this->chain($top, $bottom, $weight, $ports, null);
        $chains[$key] = [$path, \range($first, \count($this->edges) - 1)];

        return $path;
    }

    /**
     * @param array{float, float} $ports where across its top and its bottom node the chain ends
     * @param ?array{float, float, int} $label the label's width and height, and its edge
     * @return list<int> the path, top to bottom
     */
    private function chain(int $top, int $bottom, int $weight, array $ports, ?array $label): array
    {
        $owner = $this->common($this->cluster[$top], $this->cluster[$bottom]);
        $path = [$top];
        $middle = \intdiv($this->rank[$top] + $this->rank[$bottom], num2: 2);
        for ($rank = $this->rank[$top] + 1; $rank < $this->rank[$bottom]; ++$rank) {
            $node =
                null !== $label && $rank === $middle
                    ? $this->add($rank, self::LABEL, $this->mirrored ? $label[0] : 1.0, $this->mirrored ? 1.0 : $label[0], $label[1], $owner)
                    : $this->add($rank, self::VIRTUAL, 1.0, 1.0, 1.0, $owner);
            if (null !== $label && $rank === $middle) {
                $this->labels[$label[2]] = $node;
            }
            $path[] = $node;
        }
        $path[] = $bottom;
        for ($index = 1, $count = \count($path); $index < $count; ++$index) {
            $this->link($path[$index - 1], $path[$index], $weight, 1 === $index ? $ports[0] : 0.0, ($count - 1) === $index ? $ports[1] : 0.0);
        }

        return $path;
    }

    /**
     * A chain merged with the others leaving its top the same way, as far as their trunk
     * reaches, then with the others reaching its bottom the same way, as far up as theirs
     * does; new virtual nodes between extend both trunks. A stretch already made only weighs more.
     *
     * @param string $way which way the edge runs, down the ranks or up: only edges running one way merge
     * @return list<int> the path, top to bottom
     */
    private function merged(int $top, int $bottom, int $weight, string $way): array
    {
        $out = $this->trunks['out:'.$top.':'.$way] ?? [];
        $in = $this->trunks['in:'.$bottom.':'.$way] ?? [];
        $owner = $this->common($this->cluster[$top], $this->cluster[$bottom]);
        $path = [$top];
        $made = [];
        $leaving = true;
        for ($rank = $this->rank[$top] + 1; $rank < $this->rank[$bottom]; ++$rank) {
            // The trunk from the top is followed while it lasts; the one to the bottom reaches up unbroken.
            $leaving = $leaving && \array_key_exists($rank, $out);
            $node = $leaving ? $out[$rank] : $in[$rank] ?? null;
            if (null === $node) {
                $node = $this->add($rank, self::VIRTUAL, 1.0, 1.0, 1.0, $owner);
                $made[$node] = true;
            }
            $path[] = $node;
        }
        $path[] = $bottom;
        foreach (\array_slice($path, offset: 1, length: -1) as $node) {
            if (!\array_key_exists($node, $made) && !\in_array($node, $out, strict: true)) {
                break;
            }
            $this->trunks['out:'.$top.':'.$way][$this->rank[$node]] ??= $node;
        }
        foreach (\array_reverse(\array_slice($path, offset: 1, length: -1)) as $node) {
            if (!\array_key_exists($node, $made) && !\in_array($node, $in, strict: true)) {
                break;
            }
            $this->trunks['in:'.$bottom.':'.$way][$this->rank[$node]] ??= $node;
        }
        for ($index = 1, $count = \count($path); $index < $count; ++$index) {
            $this->link($path[$index - 1], $path[$index], $weight, 0.0, 0.0);
        }

        return $path;
    }

    /**
     * The stretch between two nodes on neighbouring ranks: made, or, where a merged chain made
     * it already, weighing more.
     */
    private function link(int $tail, int $head, int $weight, float $tailPort, float $headPort): void
    {
        $key = $tail.'>'.$head;
        if (Concentration::Merged === $this->concentration && \array_key_exists($key, $this->stretches)) {
            $this->edges[$this->stretches[$key]][2] += $weight;

            return;
        }
        $this->stretches[$key] = \count($this->edges);
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
            $tailPort,
            $headPort,
        ];
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
