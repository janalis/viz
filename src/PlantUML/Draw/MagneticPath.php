<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * A link meeting a package or a folder, as PlantUML's `SvekEdge` draws it: the curve dot gives
 * it, each end moved with its curve's nearest control point, first back to make room for its
 * mark, then as the box's `MagneticBorder` pushes it, below the tab where it meets the tab's
 * side or the top beside it; the mark moved with it.
 */
final class MagneticPath
{
    /** How many straight steps each curve is drawn in. */
    private const int STEPS = 12;

    /**
     * How a box's tab pushes a point on its outline, as `USymbolFolder`'s magnetic border does:
     * down the tab's height where it meets the tab's side, or the top past the tab's slant; part
     * of it along the slant, 7 either side of the tab's width.
     *
     * @param ?array{float, float} $tab the tab's width and height, null for a box without one
     * @param array{float, float} $point
     * @return array{float, float}
     */
    public static function force(?array $tab, GraphNode $box, array $point): array
    {
        if (null === $tab) {
            return [0.0, 0.0];
        }
        [$width, $height] = $tab;
        [$x, $y] = [$point[0] - ($box->x - ($box->w / 2)), $point[1] - ($box->y - ($box->h / 2))];

        return match (true) {
            ($x >= $width && $y >= 0 && $y <= $height) || ($y <= 0 && $x >= ($width + 7)) => [0.0, $height],
            $y <= 0 && $x >= ($width - 7) => [0.0, ($height * ($x - ($width - 7))) / 14],
            default => [0.0, 0.0],
        };
    }

    /**
     * The link's line and its end marks, its ends pushed.
     *
     * @param list<array{float, float}> $controls a start, then two controls and an end for each curve, cut at the boxes
     * @param array{array{float, float}, array{float, float}} $forces how its start and its end are pushed
     * @return array{list<array{float, float}>, list<Mark>, list<Mark>, list<array{float, float}>} the line, its end marks, no qualifiers' boxes, and the curve's control points
     */
    public static function drawn(PlantUmlLink $link, array $controls, array $forces, string $color, string $background, float $stroke): array
    {
        $marks = [];
        foreach ([[$link->toDecor, true, $forces[1]], [$link->fromDecor, false, $forces[0]]] as [$decor, $atEnd, $force]) {
            $points = self::sampled($controls);
            [$tip, $along] = Route::end($atEnd ? $points : \array_reverse($points));
            [$ends, $short] = Extremity::marks($decor, $tip, $along, $color, $background, $stroke);
            foreach ($ends as $mark) {
                $marks[] = $mark->mapped(static fn (array $at): array => [$at[0] + $force[0], $at[1] + $force[1]]);
            }
            $controls = self::moved($controls, $atEnd ? self::end($controls) : [0, 1], [-$along[0] * $short, -$along[1] * $short]);
        }
        $controls = self::moved(self::moved($controls, [0, 1], $forces[0]), self::end($controls), $forces[1]);

        return [self::sampled($controls), $marks, [], $controls];
    }

    /**
     * A straight line as dot gives one: a curve with its controls a third of the way along.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return list<array{float, float}>
     */
    public static function straight(array $from, array $to): array
    {
        [$dx, $dy] = [($to[0] - $from[0]) / 3, ($to[1] - $from[1]) / 3];

        return [$from, [$from[0] + $dx, $from[1] + $dy], [$from[0] + (2 * $dx), $from[1] + (2 * $dy)], $to];
    }

    /**
     * Points of the curve moved: an end, with its nearest control point.
     *
     * @param list<array{float, float}> $controls
     * @param list<int> $indexes
     * @param array{float, float} $by
     * @return list<array{float, float}>
     */
    private static function moved(array $controls, array $indexes, array $by): array
    {
        foreach ($indexes as $index) {
            $controls[$index] = [$controls[$index][0] + $by[0], $controls[$index][1] + $by[1]];
        }

        return $controls;
    }

    /**
     * @param list<array{float, float}> $controls
     * @return list<int> its end's and its last control point's
     */
    private static function end(array $controls): array
    {
        return [\count($controls) - 1, \count($controls) - 2];
    }

    /**
     * @param list<array{float, float}> $controls
     * @return list<array{float, float}>
     */
    private static function sampled(array $controls): array
    {
        $points = [$controls[0]];
        for ($start = 0, $count = \count($controls); ($start + 3) < $count; $start += 3) {
            [$a, $b, $c, $d] = \array_slice($controls, $start, length: 4);
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $t = $step / self::STEPS;
                $u = 1 - $t;
                $points[] = [
                    (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
                    (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
                ];
            }
        }

        return $points;
    }
}
