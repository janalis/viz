<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where each rank sits down the drawing, and how far each cluster reaches above its top rank
 * and below its bottom one. Ranks sit apart by their nodes' half heights and the rank
 * separation; where a cluster starts or ends between two ranks, its margin and label may
 * reach into that separation, but the cluster keeps a margin from the rank beyond. The graph
 * keeps a margin around its outermost clusters. A cluster shorter down the ranks than it must
 * be, as one laid out to be turned is for its label's width, makes its first rank reach that
 * much further up and its last that much further down, half each, as `dot` is seen to: the
 * first half a point less, the last half a point more; in a cluster, it then keeps the rank
 * separation, its margin and all, from the cluster's own nodes on the ranks beside.
 */
final class DotVertical
{
    /** @var list<float> each rank's y */
    public array $y = [];
    /** @var list<?array{float, float}> each cluster's top and bottom, null for an empty one */
    public array $boxes = [];
    public float $height = 0.0;

    /** @var list<float> */
    private array $nodeAbove = [];
    /** @var list<float> */
    private array $nodeBelow = [];
    /** @var list<?array{int, int}> each cluster's first and last rank */
    private array $span = [];
    /** @var list<float> how far each cluster reaches above its top rank */
    private array $top = [];
    /** @var list<float> how far each cluster reaches below its bottom rank */
    private array $bottom = [];
    /** @var list<float> how much further each cluster's first rank reaches up, and last down, for its length */
    private array $grownAbove = [];
    /** @var list<float> */
    private array $grownBelow = [];

    /**
     * @param list<float> $labelHeights each cluster's label height
     * @param list<float> $lengths each cluster's least length down the ranks, its margins aside; none for none
     */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly array $labelHeights,
        private readonly float $rankSeparation,
        private readonly float $padding,
        private readonly array $lengths = [],
    ) {
        $this->spans();
        $this->grownAbove = \array_fill(0, \count($this->span), value: 0.0);
        $this->grownBelow = $this->grownAbove;
        // Each pass grows the clusters still short, the deepest first as the ranks they hold move.
        for ($pass = 0; $pass <= \count($this->span); ++$pass) {
            $this->settle();
            if (!$this->grow()) {
                break;
            }
        }
        foreach ($this->span as $cluster => $range) {
            $this->boxes[$cluster] = null === $range ? null : [$this->y[$range[0]] - $this->top[$cluster], $this->y[$range[1]] + $this->bottom[$cluster]];
        }
    }

    /** Sets each rank down the drawing, from its nodes' and clusters' reach. */
    private function settle(): void
    {
        $ranks = \count($this->layers->ranks);
        $this->nodeAbove = \array_fill(0, \max(0, $ranks), value: 0.0);
        $this->nodeBelow = $this->nodeAbove;
        foreach ($this->layers->rank as $node => $rank) {
            $half = $this->layers->height[$node] / 2;
            $this->nodeAbove[$rank] = \max($this->nodeAbove[$rank], $half);
            $this->nodeBelow[$rank] = \max($this->nodeBelow[$rank], $half);
        }
        foreach ($this->span as $cluster => $range) {
            if (null === $range) {
                continue;
            }
            $this->nodeAbove[$range[0]] += $this->grownAbove[$cluster];
            $this->nodeBelow[$range[1]] += $this->grownBelow[$cluster];
        }
        [$above, $below, $graphAbove, $graphBelow] = $this->reach();
        $least = $this->beside();
        $lifts = $this->lifts();
        $ys = [];
        $y = $this->padding + \max($graphAbove, $lifts[0] ?? 0.0);
        /** @var ?array{int, float} $filled the last rank with real nodes, and where it stands */
        $filled = null;
        for ($rank = 0; $rank < $ranks; ++$rank) {
            $y += $rank > 0 ? \max($this->nodeBelow[$rank - 1] + $this->rankSeparation + $this->nodeAbove[$rank], $below[$rank - 1] + $above[$rank] + DotPosition::MARGIN, $least[$rank]) : 0.0;
            // A label over a flat edge keeps half a rank separation, to the point, from the last rank of real nodes above.
            if (\array_key_exists($rank, $lifts) && null !== $filled) {
                $y = \max($y, $filled[1] + $this->nodeBelow[$filled[0]] + $this->labelRoom() + $lifts[$rank]);
            }
            $filled = \in_array(DotLayers::REAL, \array_map(fn (int $node): int => $this->layers->kind[$node], $this->layers->ranks[$rank]), strict: true) ? [$rank, $y] : $filled;
            $ys[] = $y;
        }
        $this->y = $ys;
        $this->height = $y + $graphBelow + $this->padding;
    }

    /**
     * How far above each rank its labels over flat edges reach, as `dot` is seen to set them: a
     * label over a flat edge whose ends have nodes between them stands half the rank
     * separation, to the point, above the rank's nodes.
     *
     * @return array<int, float> by rank, for those with such a label
     */
    private function lifts(): array
    {
        $lifts = [];
        foreach ($this->layers->flat as [$tail, $head, , $labelWidth, $labelHeight]) {
            if (($labelWidth <= 0 && $labelHeight <= 0) || !self::bridging($this->layers, $tail, $head)) {
                continue;
            }
            $rank = $this->layers->rank[$tail];
            $lifts[$rank] = \max($lifts[$rank] ?? 0.0, $this->nodeAbove[$rank] + $this->labelRoom() + $labelHeight);
        }

        return $lifts;
    }

    /** Half the rank separation, rounded up to a point: a graph with labels has its ranks halved. */
    public function labelRoom(): float
    {
        return \ceil($this->rankSeparation - 1e-9);
    }

    /** Whether a flat edge's ends have nodes between them on their rank. */
    public static function bridging(DotLayers $layers, int $tail, int $head): bool
    {
        $order = $layers->ranks[$layers->rank[$tail]];

        return \abs((int) \array_search($tail, $order, strict: true) - (int) \array_search($head, $order, strict: true)) > 1;
    }

    /**
     * How far each rank must stand below the one before for each grown cluster in a cluster:
     * the rank separation between the cluster's own nodes and the grown one's reach.
     *
     * @return list<float>
     */
    private function beside(): array
    {
        $least = \array_fill(0, \count($this->layers->ranks), value: 0.0);
        foreach ($this->span as $cluster => $range) {
            $parent = $this->layers->parents[$cluster];
            if (null === $range || -1 === $parent || ($this->grownAbove[$cluster] + $this->grownBelow[$cluster]) <= 0) {
                continue;
            }
            [$first, $last] = $range;
            $before = $this->own($parent, $first - 1);
            $after = $this->own($parent, $last + 1);
            $least[$first] = null === $before ? $least[$first] : \max($least[$first], $before + $this->rankSeparation + $this->top[$cluster]);
            $least[$last + 1] = null === $after ? $least[$last + 1] ?? 0.0 : \max($least[$last + 1], $this->bottom[$cluster] + $this->rankSeparation + $after);
        }

        return $least;
    }

    /** The greatest half height of a cluster's own nodes on a rank; null for none. */
    private function own(int $cluster, int $rank): ?float
    {
        $halves = [];
        foreach ($this->layers->rank as $node => $at) {
            if ($at !== $rank || $this->layers->cluster[$node] !== $cluster) {
                continue;
            }
            $halves[] = $this->layers->height[$node] / 2;
        }

        return [] === $halves ? null : \max($halves);
    }

    /** Whether a cluster was short of its length down the ranks, which then grows by what it lacks. */
    private function grow(): bool
    {
        $grown = false;
        foreach (\array_reverse(\array_keys($this->span), preserve_keys: true) as $cluster) {
            $range = $this->span[$cluster];
            $length = $this->lengths[$cluster] ?? 0.0;
            if (null === $range || $length <= 0) {
                continue;
            }
            $lacking = $length + (2 * DotPosition::MARGIN) - ($this->y[$range[1]] + $this->bottom[$cluster] - $this->y[$range[0]] + $this->top[$cluster]);
            if ($lacking <= 0) {
                continue;
            }
            $this->grownAbove[$cluster] += \max(0.0, ($lacking - 1) / 2);
            $this->grownBelow[$cluster] += $lacking - \max(0.0, ($lacking - 1) / 2);
            $grown = true;
        }

        return $grown;
    }

    private function spans(): void
    {
        $clusters = \count($this->layers->parents);
        $this->span = \array_fill(0, \max(0, $clusters), value: null);
        foreach ($this->layers->rank as $node => $rank) {
            for ($cluster = $this->layers->cluster[$node]; -1 !== $cluster; $cluster = $this->layers->parents[$cluster]) {
                $this->span[$cluster] = null === $this->span[$cluster] ? [$rank, $rank] : [\min($this->span[$cluster][0], $rank), \max($this->span[$cluster][1], $rank)];
            }
        }
    }

    /**
     * How far each rank reaches up and down with the clusters starting or ending there, deepest
     * first, and how far the graph reaches past its first and last ranks.
     *
     * @return array{list<float>, list<float>, float, float}
     */
    private function reach(): array
    {
        $ranks = \count($this->layers->ranks);
        $above = $this->nodeAbove;
        $below = $this->nodeBelow;
        $graphAbove = $this->nodeAbove[0] ?? 0.0;
        $graphBelow = $this->nodeBelow[$ranks - 1] ?? 0.0;
        $clusters = \count($this->layers->parents);
        $this->top = \array_fill(0, \max(0, $clusters), value: 0.0);
        $this->bottom = $this->top;
        foreach (\array_reverse(\array_keys($this->layers->parents)) as $cluster) {
            $range = $this->span[$cluster];
            if (null === $range) {
                continue;
            }
            [$first, $last] = $range;
            [$high, $low] = $this->innerReach($cluster, $first, $last);
            $label = $this->labelHeights[$cluster];
            $this->top[$cluster] = $high + $this->grownAbove[$cluster] + DotPosition::MARGIN + ($label > 0 ? $label + DotPosition::MARGIN : 0.0);
            $this->bottom[$cluster] = $low + $this->grownBelow[$cluster] + DotPosition::MARGIN;
            $above[$first] = \max($above[$first], $this->top[$cluster]);
            $below[$last] = \max($below[$last], $this->bottom[$cluster]);
            if (-1 !== $this->layers->parents[$cluster]) {
                continue;
            }
            $graphAbove = 0 === $first ? \max($graphAbove, $this->top[$cluster] + DotPosition::MARGIN) : $graphAbove;
            $graphBelow = ($ranks - 1) === $last ? \max($graphBelow, $this->bottom[$cluster] + DotPosition::MARGIN) : $graphBelow;
        }

        return [$above, $below, $graphAbove, $graphBelow];
    }

    /**
     * How far a cluster's own nodes and sub-clusters reach above its first rank and below its last.
     *
     * @return array{float, float}
     */
    private function innerReach(int $cluster, int $first, int $last): array
    {
        $high = 0.0;
        $low = 0.0;
        foreach ($this->layers->rank as $node => $rank) {
            if ($this->layers->cluster[$node] !== $cluster) {
                continue;
            }
            $half = $this->layers->height[$node] / 2;
            $high = $rank === $first ? \max($high, $half) : $high;
            $low = $rank === $last ? \max($low, $half) : $low;
        }
        foreach ($this->layers->parents as $child => $parent) {
            $range = $this->span[$child];
            if ($parent !== $cluster || null === $range) {
                continue;
            }
            $high = $range[0] === $first ? \max($high, $this->top[$child]) : $high;
            $low = $range[1] === $last ? \max($low, $this->bottom[$child]) : $low;
        }

        return [$high, $low];
    }
}
