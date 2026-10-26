<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * A flat edge with nodes between its ends drawn over them, as `dot` is seen to draw one: from
 * its tail's centre up to half a rank separation above the rank, beside the first node between;
 * along to the last one; down to its head's centre. Three cubic pieces, each joining the next
 * smoothly, their shape as measured on dot's output: the rise ending at a slope that grows with
 * how steep it is, its last control 0.435 of its chord back along it; the run's controls at
 * 0.4045 of its length, along the slopes it meets.
 */
final class DotArcs
{
    /** How many straight steps each piece is drawn in. */
    private const int STEPS = 12;

    /**
     * The arc of a flat edge over the nodes between its ends, or null where none stands between.
     *
     * @return ?array{list<array{float, float}>, list<array{float, float}>, list<array{float, float}>} the curve, drawn in straight steps, its corners, and its pieces' points
     */
    public static function route(DotLayers $layers, DotPosition $position, int $tail, int $head): ?array
    {
        $rank = $layers->rank[$tail];
        $place = \array_flip($layers->ranks[$rank]);
        [$from, $to] = [\min($place[$tail], $place[$head]), \max($place[$tail], $place[$head])];
        $between = \array_slice($layers->ranks[$rank], $from + 1, $to - $from - 1);
        if ([] === $between) {
            return null;
        }
        $x = $position->x;
        $y = $position->y[$rank];
        $half = \max(\array_map(static fn (int $node): float => $layers->height[$node] / 2, $layers->ranks[$rank]));
        $level = $y - $half - ($position->rankSeparation / 2);
        $sign = $x[$head] >= $x[$tail] ? 1.0 : -1.0;
        $near = $sign > 0 ? $between[0] : $between[\count($between) - 1];
        $far = $sign > 0 ? $between[\count($between) - 1] : $between[0];
        $first = [$x[$near] - ($sign * ($sign > 0 ? $layers->left[$near] : $layers->right[$near])), $level];
        $last = [$x[$far] + ($sign * ($sign > 0 ? $layers->right[$far] : $layers->left[$far])), $level];
        [$rise, $leaving] = self::rise([$x[$tail], $y], $first, $sign);
        [$fall, $reaching] = self::rise([$x[$head], $y], $last, -$sign);
        $span = \abs($last[0] - $first[0]) * 0.4045;
        $run = [
            $first,
            [$first[0] + ($sign * $span), $first[1] - ($span * $leaving)],
            [$last[0] - ($sign * $span), $last[1] - ($span * $reaching)],
            $last,
        ];
        $pieces = [$rise, $run, [$fall[3], $fall[2], $fall[1], $fall[0]]];
        $drawn = [$rise[0]];
        foreach ($pieces as $piece) {
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $drawn[] = self::at($piece, $step / self::STEPS);
            }
        }

        return [$drawn, [[$x[$tail], $y], $first, $last, [$x[$head], $y]], [...$rise, ...$run, ...$pieces[2]]];
    }

    /**
     * The piece from a node's centre up to a corner: starting along nothing, its last control on
     * the slope it reaches the corner at, 0.435 of its chord back; and that slope.
     *
     * @param array{float, float} $centre
     * @param array{float, float} $corner
     * @return array{array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}, float}
     */
    private static function rise(array $centre, array $corner, float $sign): array
    {
        [$run, $rise] = [\abs($corner[0] - $centre[0]), $centre[1] - $corner[1]];
        $slope = 0.12 + ((0.27 * $rise) / \max($run, 1.0));
        $back = (0.435 * \hypot($run, $rise)) / \hypot(1.0, $slope);

        return [[$centre, $centre, [$corner[0] - ($sign * $back), $corner[1] + ($back * $slope)], $corner], $slope];
    }

    /**
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @return array{float, float}
     */
    private static function at(array $curve, float $t): array
    {
        $u = 1 - $t;
        [$a, $b, $c, $d] = $curve;

        return [
            (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
            (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
        ];
    }
}
