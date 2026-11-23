<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** The blocks child clusters make among a scope's items, kept from crossing one another. */
final class DotBlocks
{
    /**
     * Gives the child clusters one left-to-right order on every rank, by their mean position,
     * so that no two cluster boxes cross; plain nodes keep their slots.
     *
     * @param array<int, list<list<int>>> $itemsByRank a scope's items on each rank, in order
     * @param \Closure(int): int $childOf the child cluster a node stands in, or -1 for none
     * @return array<int, list<list<int>>> the items reordered, on every rank, or as given where fewer than two clusters
     */
    public static function consistent(array $itemsByRank, \Closure $childOf): array
    {
        $sum = [];
        $count = [];
        foreach ($itemsByRank as $items) {
            foreach ($items as $slot => $item) {
                $child = $childOf($item[0]);
                if (-1 === $child) {
                    continue;
                }
                $sum[$child] = ($sum[$child] ?? 0) + $slot;
                $count[$child] = ($count[$child] ?? 0) + 1;
            }
        }
        if (\count($sum) < 2) {
            return $itemsByRank;
        }
        $mean = [];
        foreach ($sum as $child => $total) {
            $mean[$child] = $total / $count[$child];
        }
        foreach ($itemsByRank as $rank => $items) {
            $slots = [];
            $blocks = [];
            foreach ($items as $slot => $item) {
                $child = $childOf($item[0]);
                if (-1 === $child) {
                    continue;
                }
                $slots[] = $slot;
                $blocks[] = [$mean[$child], $child, $item];
            }
            \usort($blocks, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            foreach ($slots as $index => $slot) {
                $items[$slot] = $blocks[$index][2];
            }
            $itemsByRank[$rank] = $items;
        }

        return $itemsByRank;
    }
}
