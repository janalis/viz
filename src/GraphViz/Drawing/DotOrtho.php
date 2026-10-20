<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Layout\Engine\Placement;

/**
 * Edges drawn square, as `splines=ortho` asks: each runs straight down its ranks, and across
 * the gap between two ranks on a track of its own, to the column of the next node along its
 * route. Edges leaving a node, or reaching one, spread across its side toward where they
 * go, so none run one over the other. dot routes them through a maze of cells; this follows
 * the layout's own virtual nodes, which stand in columns clear of every node.
 */
final class DotOrtho
{
    /** How far below its rank a flat edge passing others runs, in points. */
    private const float CLEAR = 18.0;

    /** @var array<int, list<array{float, float}>> each edge's path, by edge index, ranks running down */
    private array $paths = [];
    private bool $across;

    /** @var array<string, float> how far each rank's nodes reach up and down from its line, by the line */
    private array $reach = [];

    public function __construct(
        DotGraph $graph,
        private readonly Placement $placement,
    ) {
        $this->across = \in_array(\strtoupper($graph->attribute('rankdir')), ['LR', 'RL'], strict: true);
        $routes = [];
        foreach ($placement->routes as $index => $route) {
            $edge = $graph->edges[$index];
            $routes[$index] = [\array_map($this->turn(...), $route), $edge->tail, $edge->head];
        }
        $this->reach = $this->reach();
        [$sides, $gaps] = $this->crossings($routes);
        $exits = $this->exits($routes, $sides);
        $tracks = self::tracks($gaps);
        foreach ($routes as $index => [$route]) {
            $last = \count($route) - 1;
            $this->paths[$index] = $route[0][1] === $route[$last][1]
                ? $this->flat($route[0], $route[$last])
                : $this->square($route, $exits[$index], $tracks[$index] ?? []);
        }
    }

    /** @return list<array{float, float}> an edge's square path, from its tail's centre line to its head's */
    public function path(int $index): array
    {
        return \array_map($this->turn(...), $this->paths[$index]);
    }

    /**
     * A point with its axes swapped where ranks run across, so they always run down here.
     *
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private function turn(array $point): array
    {
        return $this->across ? [$point[1], $point[0]] : $point;
    }

    /**
     * How far each rank's nodes reach up and down from its line, by the line.
     *
     * @return array<string, float>
     */
    private function reach(): array
    {
        $reach = [];
        foreach ($this->placement->graph->nodes as $node) {
            [, $y] = $this->turn([$node->x, $node->y]);
            $half = ($this->across ? $node->w : $node->h) / 2;
            $reach[self::key($y)] = \max($reach[self::key($y)] ?? 0.0, $half);
        }

        return $reach;
    }

    /**
     * The sides each edge between ranks leaves and reaches, and the gaps it crosses.
     *
     * @param array<int, array{list<array{float, float}>, string, string}> $routes
     * @return array{array<string, list<array{int, int, float}>>, array<string, list<array{int, int, float, float, float}>>}
     */
    private function crossings(array $routes): array
    {
        $sides = [];
        $gaps = [];
        foreach ($routes as $index => [$route, $tail, $head]) {
            $last = \count($route) - 1;
            if ($route[0][1] === $route[$last][1]) {
                continue;
            }
            $down = $route[$last][1] > $route[0][1] ? 1 : -1;
            $sides[$tail.'|'.$down][] = [$index, 0, $route[1][0]];
            $sides[$head.'|'.-$down][] = [$index, 1, $route[$last - 1][0]];
            for ($step = 1; $step <= $last; ++$step) {
                [$from, $to] = [$route[$step - 1][1], $route[$step][1]];
                $top = $from + ($down * ($this->reach[self::key($from)] ?? 0.0));
                $bottom = $to - ($down * ($this->reach[self::key($to)] ?? 0.0));
                $gaps[self::key(\min($top, $bottom)).'|'.self::key(\max($top, $bottom))][] = [$index, $step - 1, \min($route[$step - 1][0], $route[$step][0]), $top, $bottom];
            }
        }

        return [$sides, $gaps];
    }

    /**
     * Where each edge between ranks leaves its tail and reaches its head: spread across the
     * side in the order of where the edges go; between neighbouring ranks, straight down into
     * its head where it leaves above it.
     *
     * @param array<int, array{list<array{float, float}>, string, string}> $routes
     * @param array<string, list<array{int, int, float}>> $sides
     * @return array<int, array{float, float}>
     */
    private function exits(array $routes, array $sides): array
    {
        $leavings = [];
        $arrivals = [];
        foreach ($sides as $key => $ends) {
            [$left, $right] = $this->span(\substr($key, offset: 0, length: (int) \strrpos($key, needle: '|')));
            \usort($ends, static fn (array $a, array $b): int => [$a[2], $a[0]] <=> [$b[2], $b[0]]);
            foreach ($ends as $place => [$index, $end]) {
                $at = $left + ((($place + 1) * ($right - $left)) / (\count($ends) + 1));
                if (0 === $end) {
                    $leavings[$index] = $at;
                    continue;
                }
                $arrivals[$index] = $at;
            }
        }
        $exits = [];
        foreach ($leavings as $index => $leaving) {
            [$route, , $head] = $routes[$index];
            [$left, $right] = $this->span($head);
            $straight = 2 === \count($route) && $leaving > ($left + 1) && $leaving < ($right - 1);
            $exits[$index] = [$leaving, $straight ? $leaving : $arrivals[$index]];
        }

        return $exits;
    }

    /**
     * Each edge's track across each gap it crosses: the edges crossing one spread evenly
     * across it, in the order they start across.
     *
     * @param array<string, list<array{int, int, float, float, float}>> $gaps
     * @return array<int, list<float>>
     */
    private static function tracks(array $gaps): array
    {
        $tracks = [];
        foreach ($gaps as $crossing) {
            \usort($crossing, static fn (array $a, array $b): int => [$a[2], $a[0]] <=> [$b[2], $b[0]]);
            foreach ($crossing as $place => [$index, $step, , $top, $bottom]) {
                $tracks[$index][$step] = $top + ((($place + 1) * ($bottom - $top)) / (\count($crossing) + 1));
            }
        }

        return \array_map(static function (array $steps): array {
            \ksort($steps);

            return \array_values($steps);
        }, $tracks);
    }

    /**
     * An edge between two nodes of one rank: straight across where nothing stands between
     * them, else out into the gap below the rank, along it, and back up.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return list<array{float, float}>
     */
    private function flat(array $from, array $to): array
    {
        [$low, $high] = [\min($from[0], $to[0]), \max($from[0], $to[0])];
        foreach ($this->placement->graph->nodes as $node) {
            [$x, $y] = $this->turn([$node->x, $node->y]);
            if ($y !== $from[1] || $x <= $low || $x >= $high) {
                continue;
            }
            $below = $from[1] + ($this->reach[self::key($from[1])] ?? 0.0) + self::CLEAR;

            return [$from, [$from[0], $below], [$to[0], $below], $to];
        }

        return [$from, $to];
    }

    /** @return array{float, float} how far across a node reaches, its left and right */
    private function span(string $name): array
    {
        $node = $this->placement->endpoints[$name];
        [$x] = $this->turn([$node->x, $node->y]);
        $half = ($this->across ? $node->h : $node->w) / 2;

        return [$x - $half, $x + $half];
    }

    /**
     * @param list<array{float, float}> $route the edge's centres, tail to head
     * @param array{float, float} $ends where across its tail and its head it runs
     * @param list<float> $tracks where it crosses each gap
     * @return list<array{float, float}>
     */
    private function square(array $route, array $ends, array $tracks): array
    {
        $last = \count($route) - 1;
        $x = $ends[0];
        $points = [[$x, $route[0][1]]];
        foreach ($tracks as $step => $y) {
            $next = ($step + 1) === $last ? $ends[1] : $route[$step + 1][0];
            \array_push($points, [$x, $y], [$next, $y]);
            $x = $next;
        }
        $points[] = [$x, $route[$last][1]];

        return self::straight($points);
    }

    /**
     * A path without its repeated points and without the points in line with both neighbours.
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function straight(array $points): array
    {
        $kept = [];
        foreach ($points as $point) {
            $count = \count($kept);
            if ($count > 0 && $kept[$count - 1] === $point) {
                continue;
            }
            if (
                $count > 1
                && (($kept[$count - 2][0] === $kept[$count - 1][0] && $kept[$count - 1][0] === $point[0]) || ($kept[$count - 2][1] === $kept[$count - 1][1] && $kept[$count - 1][1] === $point[1]))
            ) {
                $kept[$count - 1] = $point;
                continue;
            }
            $kept[] = $point;
        }

        return \array_values($kept);
    }

    private static function key(float $value): string
    {
        return (string) \round($value, precision: 3);
    }
}
