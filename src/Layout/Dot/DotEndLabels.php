<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Model\Direction;

/**
 * Edges' head and tail labels set where `dot` is seen to set them by default: each with a corner
 * on its end of the line, off the first corner that is clear, in an order kept as laid out
 * top-down and turned with the layout: above and left, below and left, above and right, below
 * and right. A corner is clear where it overlaps none of the nodes, the edges' labels and the
 * end labels set before it, and holds no other label's end. Where no corner is clear, the label
 * is tried below its end as laid out top-down, centred a quarter of its width right of it, then
 * on it, then a quarter left; where none of those is clear either, it takes the place that
 * overlaps least, the first of those tied. They are set in the order `dot` sets them, which the
 * caller gives: node by node in the order the nodes were made, and at each node its edges out
 * by the node they reach, in the order the nodes were made, then in the order they were
 * written, each edge's tail label before its head label. A label whose centre is given, as one
 * set by `labelangle` or `labeldistance`, stands there whatever it overlaps, and those set after
 * it keep clear of it.
 */
final class DotEndLabels
{
    /**
     * @param list<array{0: array{float, float}, 1: array{float, float}, 2?: ?array{float, float}}> $labels each label's end of the line, its size, and the centre it is set at whatever it overlaps, where fixed; in the order `dot` sets them: by the node its edge leaves, then by the node it reaches, both in the order the nodes were made, then by edge in the order written, the tail's before the head's
     * @param list<array{float, float, float, float}> $obstacles the nodes' and the edges' labels' boxes
     * @return list<array{float, float, float, float}> each label's box, in the order given
     */
    public static function placed(array $labels, array $obstacles, Direction $direction): array
    {
        $boxes = [];
        $ends = \array_map(static fn (array $label): array => $label[0], $labels);
        $places = self::places($direction);
        foreach ($labels as $index => $label) {
            [[$x, $y], [$width, $height]] = $label;
            $fixed = $label[2] ?? null;
            if (null !== $fixed) {
                $boxes[] = [$fixed[0] - ($width / 2), $fixed[1] - ($height / 2), $fixed[0] + ($width / 2), $fixed[1] + ($height / 2)];
                continue;
            }
            $others = [...$obstacles, ...$boxes];
            $best = null;
            foreach ($places as [$across, $down]) {
                $box = [$x + ((($across - 1) * $width) / 2), $y + ((($down - 1) * $height) / 2), $x + ((($across + 1) * $width) / 2), $y + ((($down + 1) * $height) / 2)];
                $cost = \array_sum(\array_map(static fn (array $other): float => self::overlap($box, $other), $others));
                // Nothing to overlap sums to an int 0, so clear is no overlap at all, not 0.0.
                if ($cost <= 0.0 && !self::holds($box, $ends, $index)) {
                    $best = [$cost, $box];
                    break;
                }
                $best = null === $best || $cost < ($best[0] - 1e-9) ? [$cost, $box] : $best;
            }
            $boxes[] = $best[1] ?? [$x, $y, $x, $y];
        }

        return $boxes;
    }

    /**
     * Labels centred off their ends as `labelangle` and `labeldistance` set them, as DOT
     * documents and as measured on dot's output: 10 points times the distance (0 to 100) from
     * the end, turned by the angle, in degrees counter-clockwise as laid out top-down, so
     * clockwise laid out bottom up or right to left, from the way the line leaves the end.
     *
     * @template T of array-key
     * @param array<T, array{array{float, float}, array{float, float}}> $ways each end and the unit way its line leaves it
     * @return array<T, array{float, float}> each label's centre
     */
    public static function polar(array $ways, float $degrees, float $distance, Direction $direction): array
    {
        $angle = (\in_array($direction, [Direction::BottomTop, Direction::RightLeft], strict: true) ? -1 : 1) * \deg2rad($degrees);
        $reach = 10 * \max(0.0, \min(100.0, $distance));

        return \array_map(static fn (array $way): array => [
            $way[0][0] + ($reach * (($way[1][0] * \cos($angle)) + ($way[1][1] * \sin($angle)))),
            $way[0][1] + ($reach * (($way[1][1] * \cos($angle)) - ($way[1][0] * \sin($angle)))),
        ], $ways);
    }

    /**
     * Where a path starts and the way it leaves, as a unit vector: toward its curve a tenth of
     * the way along the curve's first piece, where its control points start there, as measured
     * on dot's output, else toward its next point; down where it does not move.
     *
     * @param list<array{float, float}> $path
     * @param list<array{float, float}> $controls the path's curve, from the same end, where known
     * @return array{array{float, float}, array{float, float}}
     */
    public static function way(array $path, array $controls): array
    {
        [$x, $y] = $path[0];
        $next = \array_find($path, static fn (array $point): bool => \hypot($point[0] - $x, $point[1] - $y) > 0.01) ?? [$x, $y + 1];
        if (\count($controls) >= 4 && \hypot($controls[0][0] - $x, $controls[0][1] - $y) < 0.5) {
            [$a, $b, $c, $d] = $controls;
            $next = [
                (0.729 * $a[0]) + (0.243 * $b[0]) + (0.027 * $c[0]) + (0.001 * $d[0]),
                (0.729 * $a[1]) + (0.243 * $b[1]) + (0.027 * $c[1]) + (0.001 * $d[1]),
            ];
        }
        $length = \hypot($next[0] - $x, $next[1] - $y);
        if ($length < 1e-9) {
            // A curve whose control points all stand at its end leaves no way: down, as a path that does not move.
            return [[$x, $y], [0.0, 1.0]];
        }

        return [[$x, $y], [($next[0] - $x) / $length, ($next[1] - $y) / $length]];
    }

    /**
     * The places a label is tried at, in turn, as how far across and down its centre stands
     * from its end, in halves of its width and height: the four corners, then below the end as
     * laid out top-down, a quarter of its width right, on it and a quarter left; all turned with
     * the layout as `dot` turns it.
     *
     * @return list<array{float, float}>
     */
    private static function places(Direction $direction): array
    {
        $places = [];
        foreach ([[-1.0, -1.0], [-1.0, 1.0], [1.0, -1.0], [1.0, 1.0], [0.5, 1.0], [0.0, 1.0], [-0.5, 1.0]] as [$across, $down]) {
            $places[] = match ($direction) {
                Direction::TopBottom => [$across, $down],
                Direction::BottomTop => [$across, -$down],
                Direction::LeftRight => [$down, -$across],
                Direction::RightLeft => [-$down, -$across],
            };
        }

        return $places;
    }

    /**
     * Whether a box holds any other label's end strictly inside it, not on its outline.
     *
     * @param array{float, float, float, float} $box
     * @param list<array{float, float}> $ends every label's end, its own at $own
     */
    private static function holds(array $box, array $ends, int $own): bool
    {
        return \array_any($ends, static fn (array $end, int $at): bool => $at !== $own && $end[0] > $box[0] && $end[0] < $box[2] && $end[1] > $box[1] && $end[1] < $box[3]);
    }

    /**
     * How much two boxes overlap; nothing unless they overlap by more than half a point both
     * across and down, as measured on dot's output, for nodes and labels alike: so a label from
     * a line's end on a node's outline only touches the node.
     *
     * @param array{float, float, float, float} $box
     * @param array{float, float, float, float} $other
     */
    private static function overlap(array $box, array $other): float
    {
        [$across, $down] = [\min($box[2], $other[2]) - \max($box[0], $other[0]), \min($box[3], $other[3]) - \max($box[1], $other[1])];

        return $across <= 0.5 || $down <= 0.5 ? 0.0 : $across * $down;
    }
}
