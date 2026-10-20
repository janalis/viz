<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Neato;

use Janalis\Viz\Layout\Engine\LayoutEngine;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Source\Unsupported;

/**
 * neato's layout, as Gansner, Koren and North describe it in "Graph Drawing by Stress
 * Majorization" (2004): nodes placed so that their distances match the lengths of the
 * shortest paths between them, each pair weighing the inverse square of its path, by
 * majorization until the stress settles. Each connected part is laid out on its own and the
 * parts stand side by side. Edges run straight between centres; clusters are not drawn.
 */
final readonly class NeatoEngine implements LayoutEngine
{
    /** The most nodes a part may hold: each pass costs the square of its size. */
    public const int MAX_NODES = 600;
    /** The most passes of majorization. */
    private const int PASSES = 200;
    /** Majorization stops where a pass lowers the stress by less than this part of it. */
    private const float SETTLED = 1e-5;
    /** The space between two parts laid side by side, in the graph's unit. */
    private const float APART = 18.0;

    /**
     * @return \Generator<int, null, mixed, Placement>
     * @throws Unsupported when a connected part holds more than {@see self::MAX_NODES} nodes
     */
    #[\Override]
    public function place(LayoutGraph $graph): \Generator
    {
        $count = \count($graph->nodes);
        $index = [];
        foreach ($graph->nodes as $position => $node) {
            $index[$node->id] = $position;
        }
        $lengths = \array_fill(0, $count, []);
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$index[$edge->from] ?? null, $index[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $length = $edge->length > 0 ? $edge->length : 72.0;
            $lengths[$tail][$head] = \min($lengths[$tail][$head] ?? \INF, $length);
            $lengths[$head][$tail] = $lengths[$tail][$head];
        }
        $positions = [];
        $cursor = 0.0;
        foreach (self::parts($lengths) as $part) {
            if (\count($part) > self::MAX_NODES) {
                throw new Unsupported('The graph is too large to lay out natively with neato: a connected part holds '.\count($part).' nodes');
            }
            $work = self::majorize($part, $lengths);
            yield from $work;
            $placed = $work->getReturn();
            // Side by side, from the left, each part's boxes aligned at their tops.
            [$left, $top, $right] = self::extent($placed, $graph);
            foreach ($placed as $node => [$x, $y]) {
                $positions[$node] = [$x - $left + $cursor, $y - $top];
            }
            $cursor += $right - $left + self::APART;
        }

        return self::placement($graph, $positions);
    }

    /**
     * The connected parts of the graph, each its nodes in the order they come.
     *
     * @param list<array<int, float>> $lengths
     * @return list<list<int>>
     */
    private static function parts(array $lengths): array
    {
        $seen = [];
        $parts = [];
        foreach (\array_keys($lengths) as $start) {
            if (\array_key_exists($start, $seen)) {
                continue;
            }
            $seen[$start] = true;
            $part = [];
            $queue = [$start];
            while ([] !== $queue) {
                $node = \array_shift($queue);
                $part[] = $node;
                foreach (\array_keys($lengths[$node]) as $next) {
                    if (\array_key_exists($next, $seen)) {
                        continue;
                    }
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
            \sort($part);
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Each pair's shortest path, by Dijkstra's method from every node of a part.
     *
     * @param list<int> $part
     * @param list<array<int, float>> $lengths
     * @return array<int, array<int, float>>
     */
    private static function distances(array $part, array $lengths): array
    {
        $distances = [];
        foreach ($part as $source) {
            $best = [$source => 0.0];
            $queue = new \SplPriorityQueue();
            $queue->insert($source, 0.0);
            $done = [];
            while (!$queue->isEmpty()) {
                /** @var int $node */
                $node = $queue->extract();
                if (\array_key_exists($node, $done)) {
                    continue;
                }
                $done[$node] = true;
                foreach ($lengths[$node] as $next => $length) {
                    $through = $best[$node] + $length;
                    if ($through >= ($best[$next] ?? \INF)) {
                        continue;
                    }
                    $best[$next] = $through;
                    $queue->insert($next, -$through);
                }
            }
            $distances[$source] = $best;
        }

        return $distances;
    }

    /**
     * Majorizes a part's stress: from classical scaling of its distances, each pass moves each
     * node to where its pairs' distances, weighed by the inverse square of their paths, would
     * have it, until a pass hardly lowers the stress.
     *
     * @param list<int> $part
     * @param list<array<int, float>> $lengths
     * @return \Generator<int, null, mixed, array<int, array{float, float}>>
     */
    private static function majorize(array $part, array $lengths): \Generator
    {
        $distances = self::distances($part, $lengths);
        yield null;
        $positions = self::scaled($part, $distances);
        yield null;
        if (\count($part) < 2) {
            return $positions;
        }
        $stress = self::stress($part, $positions, $distances);
        for ($pass = 0; $pass < self::PASSES; ++$pass) {
            foreach ($part as $node) {
                [$x, $y] = $positions[$node];
                [$sumX, $sumY, $weights] = [0.0, 0.0, 0.0];
                foreach ($part as $other) {
                    if ($other === $node) {
                        continue;
                    }
                    $ideal = $distances[$node][$other];
                    $weight = 1 / ($ideal * $ideal);
                    [$ox, $oy] = $positions[$other];
                    $apart = \max(1e-9, \hypot($x - $ox, $y - $oy));
                    $sumX += $weight * ($ox + (($ideal * ($x - $ox)) / $apart));
                    $sumY += $weight * ($oy + (($ideal * ($y - $oy)) / $apart));
                    $weights += $weight;
                }
                $positions[$node] = [$sumX / $weights, $sumY / $weights];
            }
            yield null;
            $lowered = self::stress($part, $positions, $distances);
            $settled = ($stress - $lowered) < (self::SETTLED * $stress);
            $stress = $lowered;
            if ($settled) {
                break;
            }
        }

        return $positions;
    }

    /**
     * Classical scaling, as the paper starts from: the two leading eigenvectors of the doubly
     * centred squared distances, found by power iteration, each scaled by its eigenvalue's root.
     *
     * @param list<int> $part
     * @param array<int, array<int, float>> $distances
     * @return array<int, array{float, float}>
     */
    private static function scaled(array $part, array $distances): array
    {
        $count = \count($part);
        $squares = [];
        foreach ($part as $row => $node) {
            foreach ($part as $column => $other) {
                $squares[$row][$column] = $distances[$node][$other] ** 2;
            }
        }
        $rows = \array_map(static fn (array $row): float => \array_sum($row) / $count, $squares);
        $all = \array_sum($rows) / $count;
        $centred = [];
        foreach ($squares as $row => $values) {
            foreach ($values as $column => $value) {
                $centred[$row][$column] = -0.5 * ($value - $rows[$row] - $rows[$column] + $all);
            }
        }
        [$first, $firstValue] = self::leading($centred, 0);
        foreach ($centred as $row => $values) {
            foreach ($values as $column => $value) {
                $centred[$row][$column] = $value - ($firstValue * $first[$row] * $first[$column]);
            }
        }
        [$second, $secondValue] = self::leading($centred, 1);
        $positions = [];
        foreach ($part as $row => $node) {
            $positions[$node] = [\sqrt(\max(0.0, $firstValue)) * $first[$row], \sqrt(\max(0.0, $secondValue)) * $second[$row]];
        }

        return $positions;
    }

    /**
     * A symmetric matrix's leading eigenvector, unit long, and its eigenvalue, by power
     * iteration from a start that leans differently for each of the two asked for.
     *
     * @param array<int, array<int, float>> $matrix
     * @return array{list<float>, float}
     */
    private static function leading(array $matrix, int $which): array
    {
        $count = \count($matrix);
        $vector = [];
        for ($row = 0; $row < $count; ++$row) {
            $vector[] = \sin((($row + 1) * (1 + $which)) + $which);
        }
        $value = 0.0;
        for ($pass = 0; $pass < 100; ++$pass) {
            $next = [];
            foreach ($matrix as $values) {
                $sum = 0.0;
                foreach ($values as $column => $entry) {
                    $sum += $entry * $vector[$column];
                }
                $next[] = $sum;
            }
            $length = \sqrt(\array_sum(\array_map(static fn (float $entry): float => $entry * $entry, $next)));
            if ($length < 1e-12) {
                break;
            }
            $value = $length;
            $vector = \array_map(static fn (float $entry): float => $entry / $length, $next);
        }

        return [$vector, $value];
    }

    /**
     * @param list<int> $part
     * @param array<int, array{float, float}> $positions
     * @param array<int, array<int, float>> $distances
     */
    private static function stress(array $part, array $positions, array $distances): float
    {
        $stress = 0.0;
        foreach ($part as $place => $node) {
            foreach (\array_slice($part, $place + 1) as $other) {
                $ideal = $distances[$node][$other];
                $gap = \hypot($positions[$node][0] - $positions[$other][0], $positions[$node][1] - $positions[$other][1]) - $ideal;
                $stress += ($gap * $gap) / ($ideal * $ideal);
            }
        }

        return $stress;
    }

    /**
     * How far a part's boxes reach: left, top, right, bottom.
     *
     * @param array<int, array{float, float}> $positions
     * @return array{float, float, float, float}
     */
    private static function extent(array $positions, LayoutGraph $graph): array
    {
        [$left, $top, $right, $bottom] = [\INF, \INF, -\INF, -\INF];
        foreach ($positions as $node => [$x, $y]) {
            $box = $graph->nodes[$node];
            $left = \min($left, $x - ($box->width / 2));
            $top = \min($top, $y - ($box->height / 2));
            $right = \max($right, $x + ($box->width / 2));
            $bottom = \max($bottom, $y + ($box->height / 2));
        }

        return [$left, $top, $right, $bottom];
    }

    /** @param array<int, array{float, float}> $positions */
    private static function placement(LayoutGraph $graph, array $positions): Placement
    {
        $padding = $graph->spacing->padding;
        $nodes = [];
        $endpoints = [];
        [$width, $height] = [0.0, 0.0];
        foreach ($graph->nodes as $id => $node) {
            [$x, $y] = $positions[$id];
            $placed = new GraphNode($id, $node->id, $node->label, $x + $padding, $y + $padding, $node->width, $node->height, null, null, dashed: false);
            $nodes[$id] = $placed;
            $endpoints[$node->id] = $placed;
            $width = \max($width, $x + ($node->width / 2) + (2 * $padding));
            $height = \max($height, $y + ($node->height / 2) + (2 * $padding));
        }
        $routes = [];
        $labels = [];
        foreach ($graph->edges as $edge) {
            [$tail, $head] = [$endpoints[$edge->from] ?? null, $endpoints[$edge->to] ?? null];
            if (null === $tail || null === $head || $tail === $head) {
                continue;
            }
            $routes[$edge->index] = [[$tail->x, $tail->y], [$head->x, $head->y]];
            if ($edge->labelWidth > 0 || $edge->labelHeight > 0) {
                $labels[$edge->index] = [($tail->x + $head->x) / 2, ($tail->y + $head->y) / 2];
            }
        }

        return new Placement(new GraphLayout($width, $height, $nodes, [], []), $endpoints, ['' => $graph->direction], $routes, $labels);
    }
}
