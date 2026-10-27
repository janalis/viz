<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Layout\GraphNode;

/**
 * A curve's control points trimmed at the boxes it joins, as dot trims the curves svek reads:
 * each end's curve split where it leaves its box, the crossing found by bisection.
 */
final class CurveClip
{
    /**
     * @param list<array{float, float}> $controls a start, then two controls and an end for each curve
     * @param array<string, string> $shapes `oval` or `diamond` by node name, for a node not drawn as a box
     * @return list<array{float, float}>
     */
    public static function clipped(array $controls, GraphNode $from, GraphNode $to, array $shapes = []): array
    {
        if (\count($controls) < 4 || 0 !== ((\count($controls) - 1) % 3)) {
            return $controls;
        }
        $controls = self::cut($controls, $from, $shapes[$from->name] ?? 'box');

        return \array_reverse(self::cut(\array_reverse($controls), $to, $shapes[$to->name] ?? 'box'));
    }

    /**
     * @param list<array{float, float}> $controls
     * @return list<array{float, float}>
     */
    private static function cut(array $controls, GraphNode $box, string $shape): array
    {
        if (!BoxClip::inside($controls[0], $box, $shape)) {
            return $controls;
        }
        for ($start = 0, $count = \count($controls); ($start + 3) < $count; $start += 3) {
            $curve = \array_slice($controls, $start, length: 4);
            if (BoxClip::inside($curve[3], $box, $shape)) {
                continue;
            }
            [$in, $out] = [0.0, 1.0];
            for ($step = 0; $step < 24; ++$step) {
                $middle = ($in + $out) / 2;
                [$in, $out] = BoxClip::inside(self::split($curve, $middle)[0], $box, $shape) ? [$middle, $out] : [$in, $middle];
            }

            return [...self::split($curve, $out), ...\array_slice($controls, $start + 4)];
        }

        return $controls;
    }

    /**
     * The part of a curve from a point along it on, as de Casteljau splits it.
     *
     * @param list<array{float, float}> $curve
     * @return list<array{float, float}>
     */
    private static function split(array $curve, float $t): array
    {
        [$a, $b, $c, $d] = $curve;
        [$ab, $bc, $cd] = [self::lerp($a, $b, $t), self::lerp($b, $c, $t), self::lerp($c, $d, $t)];
        [$abc, $bcd] = [self::lerp($ab, $bc, $t), self::lerp($bc, $cd, $t)];

        return [self::lerp($abc, $bcd, $t), $bcd, $cd, $d];
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     * @return array{float, float}
     */
    private static function lerp(array $a, array $b, float $t): array
    {
        return [$a[0] + (($b[0] - $a[0]) * $t), $a[1] + (($b[1] - $a[1]) * $t)];
    }
}
