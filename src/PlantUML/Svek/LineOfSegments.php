<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Svek;

/**
 * Segments along a line pushed apart where they overlap, as PlantUML's `LineOfSegments`
 * pushes them: in order of their middles, the last overlap first, each pushing every segment
 * after it on, until none overlaps or each has had its turn; then all moved back together,
 * their middles' mean where it was.
 */
final class LineOfSegments
{
    /**
     * @param list<array{float, float}> $segments each one's start and end
     * @return list<float> each one's start, pushed apart
     */
    public static function solved(array $segments): array
    {
        $middles = \array_map(static fn (array $segment): float => ($segment[0] + $segment[1]) / 2, $segments);
        $halves = \array_map(static fn (array $segment): float => ($segment[1] - $segment[0]) / 2, $segments);
        $mean = \array_sum($middles) / \max(1, \count($middles));
        $order = \array_keys($middles);
        // As Java's sort, stable: segments with one middle keep their order.
        \usort($order, static fn (int $a, int $b): int => [$middles[$a], $a] <=> [$middles[$b], $b]);
        $turn = 0;
        while (\count($order) > 1 && $turn < \count($order) && self::pushed($order, $middles, $halves)) {
            ++$turn;
        }
        $moved = \array_sum($middles) / \max(1, \count($middles));

        return \array_map(static fn (float $middle, float $half): float => $middle + ($mean - $moved) - $half, $middles, $halves);
    }

    /**
     * Pushes on every segment after the last one to overlap the one before it.
     *
     * @param list<int> $order
     * @param list<float> $middles
     * @param list<float> $halves
     */
    private static function pushed(array $order, array &$middles, array $halves): bool
    {
        for ($index = \count($order) - 2; $index >= 0; --$index) {
            [$first, $second] = [$order[$index], $order[$index + 1]];
            $overlap = $halves[$first] + $halves[$second] - ($middles[$second] - $middles[$first]);
            if ($overlap <= 0) {
                continue;
            }
            foreach (\array_slice($order, $index + 1) as $after) {
                $middles[$after] += $overlap;
            }

            return true;
        }

        return false;
    }
}
