<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The order flat edges keep among the nodes of a rank, as dot keeps it while ordering: an
 * edge that constrains ranks keeps its tail before its head; one that does not keeps none.
 */
final class DotFlatOrder
{
    /** @var array<int, array<int, true>> by node, the nodes on its rank a flat edge keeps after it */
    private array $after = [];

    /**
     * As dot, each flat edge that constrains ranks keeps its tail before its head, but for those
     * closing a cycle, which a depth-first search from each node in turn finds; a layout turned
     * across, whose order comes out mirrored, searches from the last node and keeps them the
     * other way.
     *
     * @param list<int> $loose the edges, by their index in the layers, that do not constrain ranks
     */
    public function __construct(DotLayers $layers, bool $turned, array $loose)
    {
        $out = [];
        foreach ($layers->flat as [$tail, $head, $edge]) {
            if (\in_array($edge, $loose, strict: true)) {
                continue;
            }
            $out[$tail][] = $head;
        }
        $state = [];
        $roots = \array_keys($layers->rank);
        foreach ($turned ? \array_reverse($roots) : $roots as $root) {
            if (\array_key_exists($root, $state)) {
                continue;
            }
            $state[$root] = 1;
            $stack = [[$root, 0]];
            while ([] !== $stack) {
                [$node, $next] = $stack[\count($stack) - 1];
                $heads = $out[$node] ?? [];
                if ($next >= \count($heads)) {
                    $state[$node] = 2;
                    \array_pop($stack);
                    continue;
                }
                $stack[\count($stack) - 1][1] = $next + 1;
                $head = $heads[$next];
                $seen = $state[$head] ?? 0;
                if (1 === $seen) {
                    continue;
                }
                [$first, $last] = $turned ? [$head, $node] : [$node, $head];
                $this->after[$first][$last] = true;
                if (0 === $seen) {
                    $state[$head] = 1;
                    $stack[] = [$head, 0];
                }
            }
        }
    }

    /**
     * Whether a flat edge keeps one item before the next.
     *
     * @param list<int> $first
     * @param list<int> $second
     */
    public function keeps(array $first, array $second): bool
    {
        foreach ($first as $node) {
            foreach ($second as $other) {
                if (\array_key_exists($other, $this->after[$node] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Items in an order every flat edge among them agrees with, each as early as it can stand.
     *
     * @param list<list<int>> $items
     * @return list<list<int>>
     */
    public function leftToRight(array $items): array
    {
        $ordered = [];
        while ([] !== $items) {
            foreach ($items as $index => $item) {
                $blocked = false;
                foreach ($items as $before => $other) {
                    $blocked = $blocked || ($before !== $index && $this->keeps($other, $item));
                }
                if (!$blocked) {
                    break;
                }
            }
            $ordered[] = $items[$index];
            unset($items[$index]);
        }

        return $ordered;
    }
}
