<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Where each node stands as crossing minimisation counts crossings, as `dot`'s reported
 * crossings show: a cluster not yet ordered from inside, the outermost holding a node, takes
 * one slot of its rank for all its nodes there, every other node a slot of its own.
 */
final class DotSlots
{
    /** @var list<int> each node's slot in its rank */
    public private(set) array $slot = [];
    /** @var array<int, int> the nodes in a cluster not yet ordered from inside, and the outermost such cluster */
    public private(set) array $collapsed = [];
    /** @var array<int, array<int, int>> the slot of each cluster not yet ordered from inside on each of its ranks */
    public private(set) array $blocks = [];
    /** @var array<int, true> the clusters ordered from inside so far */
    private array $ordered = [];

    public function __construct(
        private readonly DotLayers $layers,
    ) {}

    /** Counts a cluster as ordered from inside from now on: its nodes take their own slots once recounted. */
    public function open(int $cluster): void
    {
        $this->ordered[$cluster] = true;
    }

    /** Whether any cluster has been ordered from inside. */
    public function opened(): bool
    {
        return [] !== $this->ordered;
    }

    /** Recounts every rank. */
    public function index(): void
    {
        $this->slot = \array_fill(0, \count($this->layers->rank), value: 0);
        $this->collapsed = [];
        $this->blocks = [];
        foreach (\array_keys($this->layers->ranks) as $rank) {
            $this->refresh($rank);
        }
    }

    /** Recounts the slots after two nodes of a rank traded places. */
    public function swap(int $rank, int $a, int $b): void
    {
        if (\array_key_exists($a, $this->collapsed) || \array_key_exists($b, $this->collapsed)) {
            $this->refresh($rank);

            return;
        }
        [$this->slot[$a], $this->slot[$b]] = [$this->slot[$b], $this->slot[$a]];
    }

    /** Recounts a rank's slots. */
    public function refresh(int $rank): void
    {
        foreach (\array_keys($this->blocks) as $cluster) {
            unset($this->blocks[$cluster][$rank]);
        }
        $slot = -1;
        $last = -1;
        foreach ($this->layers->ranks[$rank] as $node) {
            $outer = $this->outer($node);
            if (-1 === $outer) {
                unset($this->collapsed[$node]);
                $this->slot[$node] = ++$slot;
                $last = -1;
                continue;
            }
            $this->collapsed[$node] = $outer;
            $slot += (int) ($outer !== $last);
            $last = $outer;
            $this->slot[$node] = $slot;
            $this->blocks[$outer][$rank] = $slot;
        }
    }

    /** The outermost cluster holding a node not yet ordered from inside, or -1 for none. */
    private function outer(int $node): int
    {
        $outer = -1;
        for ($cluster = $this->layers->cluster[$node]; -1 !== $cluster; $cluster = $this->layers->parents[$cluster]) {
            $outer = \array_key_exists($cluster, $this->ordered) ? $outer : $cluster;
        }

        return $outer;
    }
}
