<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where each rank sits down the drawing, and how far each cluster reaches above its top rank
 * and below its bottom one. Ranks sit apart by their nodes' half heights and the rank
 * separation; where a cluster starts or ends between two ranks, its margin and label may
 * reach into that separation, but the cluster keeps a margin from the rank beyond. The graph
 * keeps a margin around its outermost clusters.
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

    /** @param list<float> $labelHeights each cluster's label height */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly array $labelHeights,
        float $rankSeparation,
        float $padding,
    ) {
        $ranks = \count($layers->ranks);
        $this->nodeAbove = \array_fill(0, \max(0, $ranks), value: 0.0);
        $this->nodeBelow = $this->nodeAbove;
        foreach ($layers->rank as $node => $rank) {
            $half = $layers->height[$node] / 2;
            $this->nodeAbove[$rank] = \max($this->nodeAbove[$rank], $half);
            $this->nodeBelow[$rank] = \max($this->nodeBelow[$rank], $half);
        }
        $this->spans();
        [$above, $below, $graphAbove, $graphBelow] = $this->reach();
        $y = $padding + $graphAbove;
        for ($rank = 0; $rank < $ranks; ++$rank) {
            $y += $rank > 0 ? \max($this->nodeBelow[$rank - 1] + $rankSeparation + $this->nodeAbove[$rank], $below[$rank - 1] + $above[$rank] + DotPosition::MARGIN) : 0.0;
            $this->y[] = $y;
        }
        $this->height = $y + $graphBelow + $padding;
        foreach ($this->span as $cluster => $range) {
            $this->boxes[$cluster] = null === $range ? null : [$this->y[$range[0]] - $this->top[$cluster], $this->y[$range[1]] + $this->bottom[$cluster]];
        }
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
            $this->top[$cluster] = $high + DotPosition::MARGIN + ($label > 0 ? $label + DotPosition::MARGIN : 0.0);
            $this->bottom[$cluster] = $low + DotPosition::MARGIN;
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
