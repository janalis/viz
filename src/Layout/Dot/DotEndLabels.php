<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Model\Direction;

/**
 * Edges' head and tail labels set where `dot` is seen to set them by default: each with a corner
 * on its end of the line, off the corner that overlaps least of the nodes, the edges' labels and
 * the end labels set before it, the first of those tied in an order kept as laid out top-down
 * and turned with the layout: above and left, below and left, above and right, below and right.
 * They are set left to right as laid out top-down, each at its end's x to the point, the
 * higher first where they stand level.
 */
final class DotEndLabels
{
    /**
     * @param list<array{array{float, float}, array{float, float}}> $labels each label's end of the line and its size, in the order the edges were written, the tail's before the head's
     * @param list<array{float, float, float, float}> $obstacles the nodes' and the edges' labels' boxes
     * @return list<array{float, float, float, float}> each label's box, in the order given
     */
    public static function placed(array $labels, array $obstacles, Direction $direction): array
    {
        $order = \array_keys($labels);
        \usort($order, static fn (int $one, int $other): int => self::rank($labels[$one][0], $direction) <=> self::rank($labels[$other][0], $direction));
        $boxes = [];
        foreach ($order as $index) {
            [[$x, $y], [$width, $height]] = $labels[$index];
            $best = null;
            foreach (self::corners($direction) as [$across, $down]) {
                $box = [\min($x, $x + ($across * $width)), \min($y, $y + ($down * $height)), \max($x, $x + ($across * $width)), \max($y, $y + ($down * $height))];
                $cost = \array_sum(\array_map(static fn (array $other): float => self::overlap($box, $other), [...$obstacles, ...$boxes]));
                $best = null === $best || $cost < ($best[0] - 1e-9) ? [$cost, $box] : $best;
            }
            $boxes[$index] = $best[1] ?? [$x, $y, $x, $y];
        }
        \ksort($boxes);

        return \array_values($boxes);
    }

    /**
     * Where a label comes in the order they are set: across, to the point, then down, as laid out top-down.
     *
     * @param array{float, float} $end
     * @return array{float, float}
     */
    private static function rank(array $end, Direction $direction): array
    {
        [$x, $y] = $end;

        return match ($direction) {
            Direction::TopBottom => [\round($x), $y],
            Direction::BottomTop => [\round($x), -$y],
            Direction::LeftRight => [\round($y), $x],
            Direction::RightLeft => [\round($y), -$x],
        };
    }

    /**
     * The corners a label is tried off, in turn, as which way across and down it reaches from its end.
     *
     * @return list<array{int, int}>
     */
    private static function corners(Direction $direction): array
    {
        return match ($direction) {
            Direction::TopBottom => [[-1, -1], [-1, 1], [1, -1], [1, 1]],
            Direction::BottomTop => [[-1, 1], [-1, -1], [1, 1], [1, -1]],
            Direction::LeftRight => [[-1, 1], [1, 1], [-1, -1], [1, -1]],
            Direction::RightLeft => [[1, 1], [1, -1], [-1, 1], [-1, -1]],
        };
    }

    /**
     * How much two boxes overlap; nothing where they only touch, within a point either way, as
     * a label from a line's end on a node's outline only touches the node.
     *
     * @param array{float, float, float, float} $box
     * @param array{float, float, float, float} $other
     */
    private static function overlap(array $box, array $other): float
    {
        [$across, $down] = [\min($box[2], $other[2]) - \max($box[0], $other[0]), \min($box[3], $other[3]) - \max($box[1], $other[1])];

        return $across < 1 || $down < 1 ? 0.0 : $across * $down;
    }
}
