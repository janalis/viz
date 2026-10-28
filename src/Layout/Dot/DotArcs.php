<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * A flat edge with nodes between its ends drawn over them, as `dot` is seen to draw one: from
 * its tail's centre up above the rank, beside the first node between; along to the last one;
 * down to its head's centre. Of n such edges between the same two nodes, the kth, in the order
 * written, runs k rank separations in n + 1 above the rank, its corners half a node separation,
 * to the point down, outside the nodes between, and k node separations in n + 1 back in. Three
 * cubic pieces, each joining the next smoothly, as measured on dot's output: the rise reaching its
 * corner at half the slope of its chord's angle, starting along nothing; each control 4/9 of its
 * piece's chord from its end, along the slope there.
 */
final class DotArcs
{
    /** How many straight steps each piece is drawn in. */
    private const int STEPS = 12;

    /**
     * The arc of a flat edge over the nodes between its ends, or null where none stands between.
     *
     * @return ?array{list<array{float, float}>, list<array{float, float}>, list<array{float, float}>} the curve, drawn in straight steps, its corners, and its pieces' points, each piece's start its last's end
     */
    public static function route(DotLayers $layers, DotPosition $position, int $tail, int $head, int $k = 1, int $n = 1): ?array
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
        $level = $y - $half - (($k * $position->rankSeparation) / ($n + 1));
        $inside = (($k * $position->nodeSeparation) / ($n + 1)) - \floor($position->nodeSeparation / 2);
        $sign = $x[$head] >= $x[$tail] ? 1.0 : -1.0;
        $near = $sign > 0 ? $between[0] : $between[\count($between) - 1];
        $far = $sign > 0 ? $between[\count($between) - 1] : $between[0];
        $first = [$x[$near] - ($sign * (($sign > 0 ? $layers->left[$near] : $layers->right[$near]) - $inside)), $level];
        $last = [$x[$far] + ($sign * (($sign > 0 ? $layers->right[$far] : $layers->left[$far]) - $inside)), $level];
        $gap = $position->nodeSeparation / 2;
        [$rise, $leaving] = self::rise([$x[$tail], $y], $first, $sign, [$first[0] - ($sign * $gap), $y - $half]);
        [$fall, $reaching] = self::rise([$x[$head], $y], $last, -$sign, [$last[0] + ($sign * $gap), $y - $half]);
        $pieces = [$rise, self::run($first, $last, $sign, [$leaving, $reaching], $y - $half - $level), [$fall[3], $fall[2], $fall[1], $fall[0]]];
        $drawn = [$rise[0]];
        foreach ($pieces as $piece) {
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $drawn[] = self::at($piece, $step / self::STEPS);
            }
        }

        return [$drawn, [[$x[$tail], $y], $first, $last, [$x[$head], $y]], [...$rise, ...\array_slice($pieces[1], offset: 1), ...\array_slice($pieces[2], offset: 1)]];
    }

    /**
     * The piece along the top from one corner to the other, leaving and reaching them along the
     * slopes it meets, each control 4/9 of its length from its corner — halved, as `dot` is seen
     * to fit it, while it would rise further above its corners than they stand above the rank.
     *
     * @param array{float, float} $first
     * @param array{float, float} $last
     * @param array{float, float} $slopes the slopes it leaves and reaches its corners along
     * @param float $room how far its corners stand above the rank
     * @return array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}
     */
    private static function run(array $first, array $last, float $sign, array $slopes, float $room): array
    {
        $piece = static function (float $share) use ($first, $last, $sign, $slopes): array {
            $reach = $share * \abs($last[0] - $first[0]);
            [$out, $in] = [$reach / \hypot(1.0, $slopes[0]), $reach / \hypot(1.0, $slopes[1])];

            return [$first, [$first[0] + ($sign * $out), $first[1] - ($out * $slopes[0])], [$last[0] - ($sign * $in), $last[1] - ($in * $slopes[1])], $last];
        };
        $share = 4 / 9;
        while (($first[1] - \min(\array_map(static fn (int $step): float => self::at($piece($share), $step / 64)[1], \range(0, end: 64)))) > $room) {
            $share /= 2;
        }

        return $piece($share);
    }

    /**
     * The piece from a node's centre up to a corner: starting along nothing, reaching the corner
     * at half the slope of its chord's angle — or, where its chord passes below the rank's top
     * half a node separation back from the corner, half that of the line from there — its last
     * control 4/9 of its chord back along that slope; and that slope.
     *
     * @param array{float, float} $centre
     * @param array{float, float} $corner
     * @param array{float, float} $edge where the rank's top is half a node separation back from the corner
     * @return array{array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}, float}
     */
    private static function rise(array $centre, array $corner, float $sign, array $edge): array
    {
        [$run, $rise] = [\abs($corner[0] - $centre[0]), $centre[1] - $corner[1]];
        [$across, $above] = [\abs($corner[0] - $edge[0]), $edge[1] - $corner[1]];
        // As `dot`'s route bends round the rank's top where the chord would cut below it.
        $slope = ($rise * $across) > ($run * $above) ? \tan(\atan2($above, $across) / 2) : \tan(\atan2($rise, $run) / 2);
        $back = ((4 / 9) * \hypot($run, $rise)) / \hypot(1.0, $slope);

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
