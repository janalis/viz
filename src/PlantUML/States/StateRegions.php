<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

/**
 * The concurrent regions of a composite state a transition crosses into, as svek lays them
 * out: those no transition crosses into each laid out on its own, an image in the composite's
 * cluster; the others' states in the cluster themselves.
 */
final class StateRegions
{
    /**
     * The regions svek lays out each on its own: those no transition crosses into, where there
     * are several.
     *
     * @return list<int>
     */
    public static function images(PlantUmlStates $states, string $composite): array
    {
        $count = \count($states->composites[$composite]);
        if (0 === $count) {
            return [];
        }
        $crossed = self::crossedIndexes($states, $composite);

        return \array_values(\array_filter(\range(0, $count), static fn (int $index): bool => !\array_key_exists($index, $crossed)));
    }

    /**
     * The regions whose states stand in the composite's cluster themselves.
     *
     * @return list<int>
     */
    public static function crossed(PlantUmlStates $states, string $composite): array
    {
        return \array_values(\array_diff(\range(0, \count($states->composites[$composite])), self::images($states, $composite)));
    }

    /** @return array<int, true> the regions some transition crosses into or out of */
    private static function crossedIndexes(PlantUmlStates $states, string $composite): array
    {
        $crossed = [];
        foreach ($states->links as $link) {
            [$from, $to] = [self::regionIn($states, $link->from, $composite), self::regionIn($states, $link->to, $composite)];
            if ($from !== $to) {
                $crossed[$from ?? -1] = true;
                $crossed[$to ?? -1] = true;
            }
        }

        return $crossed;
    }

    /** The region of a composite state a state stands in, however deep; null outside it. */
    private static function regionIn(PlantUmlStates $states, string $id, string $composite): ?int
    {
        $node = $states->nodes[$id];
        while (null !== $node->group && $node->group !== $composite) {
            $node = $states->nodes[$node->group];
        }

        return null === $node->group ? null : $node->region;
    }
}
