<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * The connected components of a ranked graph, as crossing minimisation orders them: found by a
 * depth-first search from each real node in turn, following edges down, up, then flat, each
 * node numbered in the order its component's search meets it; the nodes of one outermost
 * cluster belong together, as if joined, and a flat edge into or out of a cluster joins nothing.
 */
final class DotComponents
{
    /**
     * @return array{list<int>, list<int>, int} each node's component, its place in its component's
     *     visit order, and how many components there are
     */
    public static function of(DotLayers $layers, DotCrossings $crossings): array
    {
        $count = \count($layers->rank);
        $flat = \array_fill(0, \max(0, $count), []);
        foreach ($layers->flat as [$tail, $head]) {
            // As `dot` reports its components, a flat edge into or out of a cluster joins nothing.
            if (self::outermost($layers, $tail) !== self::outermost($layers, $head)) {
                continue;
            }
            $flat[$tail][] = $head;
            $flat[$head][] = $tail;
        }
        $members = [];
        for ($node = 0; $node < $count; ++$node) {
            $outer = self::outermost($layers, $node);
            if (-1 !== $outer) {
                $members[$outer][] = $node;
            }
        }
        $component = \array_fill(0, \max(0, $count), -1);
        $visit = \array_fill(0, \max(0, $count), value: 0);
        $components = 0;
        for ($root = 0; $root < $count; ++$root) {
            if (-1 !== $component[$root]) {
                continue;
            }
            $current = $components++;
            $visited = 0;
            $stack = [$root];
            while ([] !== $stack) {
                $node = \array_pop($stack);
                if (-1 !== $component[$node]) {
                    continue;
                }
                $component[$node] = $current;
                $visit[$node] = $visited++;
                $next = [
                    ...\array_map(static fn (int $edge): int => $crossings->far($edge, Side::Below), $crossings->down[$node]),
                    ...\array_map(static fn (int $edge): int => $crossings->far($edge, Side::Above), $crossings->up[$node]),
                    ...$flat[$node],
                    ...($members[self::outermost($layers, $node)] ?? []),
                ];
                foreach (\array_reverse($next) as $other) {
                    $stack[] = $other;
                }
            }
        }

        return [$component, $visit, $components];
    }

    private static function outermost(DotLayers $layers, int $node): int
    {
        $outermost = -1;
        $cluster = $layers->cluster[$node];
        while (-1 !== $cluster) {
            $outermost = $cluster;
            $cluster = $layers->parents[$cluster];
        }

        return $outermost;
    }
}
