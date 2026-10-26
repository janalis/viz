<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Svek;

use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\LayoutRankSet;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Engine\RankKind;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Shape;

/**
 * The graph PlantUML's svek hands to dot, built the way svek builds it and laid out by the
 * native dot engine: every entity a box of the size it is drawn, a link's dashes less one
 * its minimum length, its label a box of its own, groups as clusters with room for their
 * title, links across first, then nodes cluster by cluster, then the rest. Separations are
 * svek's: 35 points between nodes and 60 between ranks, unless `nodesep` or `ranksep` say.
 */
final class Svek
{
    /** The prefixes of the clusters svek wraps each cluster in, and holds its content in. */
    private const string OUTER = "\0p0:";
    private const string INNER = "\0p1:";
    /** The prefixes of the two more a cluster a link starts or ends at stands in, and holds its content in. */
    private const string AROUND = "\0a:";
    private const string WITHIN = "\0i:";
    /** The prefix of the cluster a cluster with points on its border holds its title and content in, inside the points' ranks. */
    private const string BORDERED = "\0ee:";
    /** How wide a point's label may be before svek writes the point as a port in a table, as dot sizes a table: its least 54 by 36, 8 each side of the table. */
    private const int PORTED = 40;
    /** The prefix of the point svek puts in a cluster that holds nothing but the points on its border. */
    private const string EMPTY = "\0e:";
    /** The room dot is seen to give the empty cells across either side of a node written as a table, and above and below it. */
    private const float TABLE_SIDE = 8.0;
    private const float TABLE_TOP = 4.0;

    /** @var array<string, array{string, float, float, ?string}> by id, which each carries, as a numeric one would not stay a string key */
    private array $nodes = [];
    /** @var array<string, Shape> each node's outline, by id */
    private array $shapes = [];
    /** @var array<string, array{string, ?string, float, float}> */
    private array $clusters = [];
    /** @var array<string, true> the clusters `together` packs, which svek protects with no clusters of its own */
    private array $packed = [];
    /** @var array<string, true> the clusters a link starts or ends at, which svek wraps twice more */
    private array $linked = [];
    /** @var array<string, true> the nodes standing in their cluster itself, not in its inner protection */
    private array $points = [];
    /** @var list<array{string, string, int, float, float, bool, bool}> */
    private array $edges = [];
    /** @var array<string, bool> the points on a cluster's border, by id: true for an entry, false for an exit */
    private array $borders = [];
    /** @var array<string, float> how far right of its node's centre each point on a border stands */
    private array $ports = [];
    /** @var array<string, true> the clusters with points on their border */
    private array $bordered = [];
    /** @var array<string, array{float, float, float, float}> the room svek keeps around a node, as its table's cells about the node's own: top, right, bottom and left */
    private array $margins = [];

    /** A node, with the outline links stop at, as `BoxClip` names it: `oval`, `diamond` or `box`. */
    public function node(string $id, float $width, float $height, ?string $cluster = null, string $shape = 'box'): void
    {
        $this->nodes[$id] = [$id, $width, $height, $cluster];
        $this->shapes[$id] = match ($shape) {
            'oval' => Shape::Ellipse,
            'diamond' => Shape::Diamond,
            default => Shape::Box,
        };
    }

    /**
     * Room kept around a node, as svek keeps it for a link's qualifier: the node written as a
     * table, the node's own box a cell with a port its links end at, cells of that room around
     * it. Room asked for twice is the more of the two on each side.
     */
    public function margins(string $id, float $top, float $right, float $bottom, float $left): void
    {
        $kept = $this->margins[$id] ?? [0.0, 0.0, 0.0, 0.0];
        $this->margins[$id] = [\max($kept[0], $top), \max($kept[1], $right), \max($kept[2], $bottom), \max($kept[3], $left)];
    }

    /** A cluster, with the size of its title, which it keeps room for above its content. */
    public function cluster(string $id, ?string $parent, float $titleWidth = 0.0, float $titleHeight = 0.0): void
    {
        $this->clusters[$id] = [$id, $parent, $titleWidth, $titleHeight];
    }

    /**
     * The point a link to or from a cluster ends at, as svek puts one in the cluster: in it
     * directly, outside its inner protections, which the cluster then gains two more of.
     */
    public function point(string $id, string $cluster): void
    {
        // As svek writes it: a point a hundredth of an inch across.
        $this->nodes[$id] = [$id, 0.72, 0.72, $cluster];
        $this->points[$id] = true;
        $this->linked[$cluster] = true;
    }

    /**
     * An entry or exit point on a cluster's border, as svek writes one: a square 12 across,
     * ranked first or last in the cluster; its label wider than 40, a table about a port that
     * square, 40 narrower than the label, whose square its links end at. Svek reads the point
     * from the port's column, which dot, as measured on it, makes a third of the table's width
     * and 24 more, never under 12, centred: the point's corner is at the column's.
     */
    public function border(string $id, string $cluster, bool $entry, float $labelWidth): void
    {
        $width = (int) $labelWidth;
        $table = \max(10, $width - self::PORTED);
        $this->nodes[$id] = $width > self::PORTED ? [$id, \max(54.0, $table + 16.0), 36.0, $cluster] : [$id, 12.0, 12.0, $cluster];
        $this->ports[$id] = $width > self::PORTED ? 6.0 - (\max(12.0, ($table + 24) / 3) / 2) : 0.0;
        $this->borders[$id] = $entry;
        $this->bordered[$cluster] = true;
    }

    /**
     * A pin or an expansion on a cluster's border, as svek writes one: a rectangle its own
     * size, ranked first in the cluster for an input or last for an output, with no port.
     */
    public function pin(string $id, string $cluster, bool $input, float $width, float $height): void
    {
        $this->nodes[$id] = [$id, $width, $height, $cluster];
        $this->borders[$id] = $input;
        $this->bordered[$cluster] = true;
    }

    /**
     * Where across a node written as a table its links end, along its rank's order: its own
     * cell's centre, as a part of the table's width, or of its height laid out across.
     */
    private function port(string $id, Direction $direction): float
    {
        if (!\array_key_exists($id, $this->margins)) {
            return 0.0;
        }
        [$top, $right, $bottom, $left] = \array_map(\floor(...), $this->margins[$id]);
        [$width, $height] = $this->table($id);
        $across = \in_array($direction, [Direction::LeftRight, Direction::RightLeft], strict: true);

        return $across ? (($top - $bottom) / 2) / $height : (($left - $right) / 2) / $width;
    }

    /**
     * The size of a node written as a table, as dot is seen to give it: each size svek writes
     * taken in whole points down, and 8 more either side across, 4 above and below.
     *
     * @return array{float, float}
     */
    private function table(string $id): array
    {
        [$top, $right, $bottom, $left] = \array_map(\floor(...), $this->margins[$id]);
        [, $width, $height] = $this->nodes[$id];

        return [\floor($width) + $left + $right + (2 * self::TABLE_SIDE), \floor($height) + $top + $bottom + (2 * self::TABLE_TOP)];
    }

    /** How far right of where its links end svek reads a point on a border to stand, from its port's column. */
    public function shift(string $id): float
    {
        return $this->ports[$id] ?? 0.0;
    }

    /** Nodes kept together, as `together` packs them: a cluster with no title and no protection around it. */
    public function together(string $id, ?string $parent): void
    {
        $this->clusters[$id] = [$id, $parent, 0.0, 0.0];
        $this->packed[$id] = true;
    }

    /**
     * The label svek gives a cluster, as PlantUML's cluster header measures it: its title over
     * its description, 5 apart, with the room its symbol's shape needs, whole points, 5 short.
     *
     * @return array{float, float}
     */
    public static function label(float $titleWidth, float $titleHeight, float $descriptionWidth = 0.0, float $descriptionHeight = 0.0, float $shapeWidth = 0.0, float $shapeHeight = 0.0): array
    {
        if (0.0 === $titleWidth) {
            return [0.0, 0.0];
        }

        return [\floor(\max($titleWidth, $descriptionWidth)) + $shapeWidth, \floor($titleHeight + $descriptionHeight + ($descriptionHeight > 0 ? 5 : 0) + $shapeHeight) - 5];
    }

    /** How much room svek leaves around a link's label, both sides together: 1 each side, 6 on a loop. */
    public static function labelMargin(string $from, string $to): float
    {
        return $from === $to ? 12.0 : 2.0;
    }

    /**
     * An edge, by the dashes of the link it stands for: one lays its ends side by side. Its index in the placement's routes.
     * Its label's size, its room included, in whole points down, as svek writes it into the graph.
     */
    public function edge(string $from, string $to, int $length, float $labelWidth = 0.0, float $labelHeight = 0.0, bool $constraint = true, bool $reversed = false): int
    {
        $this->edges[] = [$from, $to, \max(0, $length - 1), \floor($labelWidth), \floor($labelHeight), $constraint, $reversed];

        return \count($this->edges) - 1;
    }

    /** @return \Generator<int, null, mixed, Placement> */
    public function place(Direction $direction = Direction::TopBottom, float $nodeSeparation = 35.0, float $rankSeparation = 60.0): \Generator
    {
        $edges = [];
        foreach ($this->edges as $index => [$from, $to, $minimum, $labelWidth, $labelHeight, $constraint]) {
            $edges[] = new LayoutEdge(
                $index,
                $from,
                $to,
                $minimum,
                constraint: $constraint,
                labelWidth: $labelWidth,
                labelHeight: $labelHeight,
                tailPort: $this->port($from, $direction),
                headPort: $this->port($to, $direction),
            );
        }
        // Svek writes links laid side by side before the nodes, and the others after them.
        \usort($edges, static fn (LayoutEdge $a, LayoutEdge $b): int => [(int) (0 !== $a->minLength), $a->index] <=> [(int) (0 !== $b->minLength), $b->index]);
        // As svek, each cluster stands in a cluster of its own and holds its content in another,
        // with no label: protections, each keeping dot's margin around what it holds.
        $clusters = [];
        foreach ($this->clusters as [$id, $parent, $titleWidth, $titleHeight]) {
            $holder = null === $parent ? null : $this->holder($parent);
            if (\array_key_exists($id, $this->packed)) {
                $clusters[] = new LayoutCluster($id, '', $holder, order: \count($clusters));
                continue;
            }
            // A cluster a link starts or ends at stands in one more, and holds its content in one more.
            $linked = \array_key_exists($id, $this->linked);
            if ($linked) {
                $clusters[] = new LayoutCluster(self::AROUND.$id, '', $holder, order: \count($clusters));
                $holder = self::AROUND.$id;
            }
            if (\array_key_exists($id, $this->bordered)) {
                // No protections: the points' ranks in the cluster, the title and content in one more.
                $clusters[] = new LayoutCluster($id, '', $holder, order: \count($clusters));
                $clusters[] = new LayoutCluster(self::BORDERED.$id, '', $id, minWidth: $titleWidth, labelHeight: $titleHeight, order: \count($clusters));
                if ($linked) {
                    $clusters[] = new LayoutCluster(self::WITHIN.$id, '', self::BORDERED.$id, order: \count($clusters));
                }
                continue;
            }
            $clusters[] = new LayoutCluster(self::OUTER.$id, '', $holder, order: \count($clusters));
            $clusters[] = new LayoutCluster($id, '', self::OUTER.$id, minWidth: $titleWidth, labelHeight: $titleHeight, order: \count($clusters));
            if ($linked) {
                $clusters[] = new LayoutCluster(self::WITHIN.$id, '', $id, order: \count($clusters));
            }
            $clusters[] = new LayoutCluster(self::INNER.$id, '', $linked ? self::WITHIN.$id : $id, order: \count($clusters));
        }
        // As svek, a cluster holding nothing but its points holds a point in their place.
        foreach ($this->clusters as [$cluster]) {
            if (!\array_key_exists($cluster, $this->bordered)) {
                continue;
            }
            $nodes = \array_filter($this->nodes, fn (array $node): bool => $node[3] === $cluster && !\array_key_exists($node[0], $this->borders));
            if ([] === $nodes && [] === \array_filter($this->clusters, static fn (array $child): bool => $child[1] === $cluster)) {
                $this->nodes[self::EMPTY.$cluster] = [self::EMPTY.$cluster, 0.72, 0.72, $cluster];
                $this->points[self::EMPTY.$cluster] = true;
            }
        }
        $graph = new LayoutGraph($this->ordered(), $edges, $clusters, $direction, new Spacing($rankSeparation, $nodeSeparation, 4.0), rankSets: $this->ranks());
        $placing = new DotEngine()->place($graph);
        yield from $placing;
        $placement = $placing->getReturn();
        $layout = $placement->graph;
        $kept = \array_values(\array_filter($layout->clusters, fn (GraphCluster $cluster): bool => \array_key_exists($cluster->name, $this->clusters)));

        // A point written as a port has its links end at its port's square, which dot keeps at its centre.
        $endpoints = $placement->endpoints;
        // A node written as a table stands as its own cell, which its links end at.
        foreach ($this->margins as $id => [$top, $right, $bottom, $left]) {
            [$node, [, $width, $height]] = [$endpoints[$id], $this->nodes[$id]];
            [$x, $y] = [$node->x - ($node->w / 2) + self::TABLE_SIDE + \floor($left), $node->y - ($node->h / 2) + self::TABLE_TOP + \floor($top)];
            $endpoints[$id] = $node->at($x + ($width / 2), $y + ($height / 2))->sized($width, $height);
        }
        foreach (\array_keys($this->ports) as $id) {
            $endpoints[$id] = $endpoints[$id]->sized(12.0, 12.0);
        }

        return new Placement(
            new GraphLayout($layout->width, $layout->height, $layout->nodes, $layout->edges, $kept),
            $endpoints,
            $placement->directions,
            $placement->routes,
            $placement->labels,
            $placement->splines,
        );
    }

    /**
     * The nodes in the order dot creates them from the file svek writes: the starts of the
     * links turned round, the last first, which svek writes before anything; then the ends of
     * the links laid side by side, written next; then the rest, at the top first, then cluster
     * by cluster as they nest.
     *
     * @return list<LayoutNode>
     */
    private function ordered(): array
    {
        /** @var list<string> $first */
        $first = [];
        foreach ($this->edges as [$from, , , , , , $reversed]) {
            // Only a node at the top, not one in a cluster, as `printCluster1` writes the top's.
            if (!$reversed || !\array_key_exists($from, $this->nodes) || null !== $this->nodes[$from][3]) {
                continue;
            }
            \array_unshift($first, $from);
        }
        foreach ($this->edges as [$from, $to, $minimum]) {
            if (0 !== $minimum) {
                continue;
            }
            \array_push($first, $from, $to);
        }
        $rank = \array_flip(\array_values(\array_unique($first)));
        $nodes = $this->declared();
        \usort($nodes, static fn (LayoutNode $a, LayoutNode $b): int => [$rank[$a->id] ?? \PHP_INT_MAX, $a->order] <=> [$rank[$b->id] ?? \PHP_INT_MAX, $b->order]);

        return $nodes;
    }

    /** @return list<LayoutNode> the nodes as svek declares them: at the top first, then cluster by cluster as they nest */
    private function declared(): array
    {
        $ordered = [];
        foreach ([null, ...\array_column($this->clusters, 0)] as $cluster) {
            $mine = \array_filter($this->nodes, static fn (array $node): bool => $node[3] === $cluster);
            // As svek writes a cluster's points before its content, its entries first.
            $entries = \array_filter($mine, fn (array $node): bool => $this->borders[$node[0]] ?? false);
            $exits = \array_filter($mine, fn (array $node): bool => false === ($this->borders[$node[0]] ?? null));
            foreach ([...$entries, ...$exits, ...\array_diff_key($mine, $entries, $exits)] as [$id, $width, $height, $owner]) {
                $holder = match (true) {
                    null === $owner, \array_key_exists($id, $this->borders) => $owner,
                    \array_key_exists($id, $this->points) => \array_key_exists($owner, $this->bordered) ? self::BORDERED.$owner : $owner,
                    default => $this->holder($owner),
                };
                [$width, $height] = \array_key_exists($id, $this->margins) ? $this->table($id) : [$width, $height];
                $ordered[] = new LayoutNode($id, '', $width, $height, $holder, \count($ordered), shape: $this->shapes[$id] ?? Shape::Box);
            }
        }

        return $ordered;
    }

    /** @return list<LayoutRankSet> each cluster's entries on its first rank and its exits on its last, alone */
    private function ranks(): array
    {
        $sets = [];
        foreach ($this->clusters as [$cluster]) {
            if (!\array_key_exists($cluster, $this->bordered)) {
                continue;
            }
            $entries = [];
            $exits = [];
            foreach ($this->borders as $id => $entry) {
                if ($this->nodes[$id][3] !== $cluster) {
                    continue;
                }
                $entry ? ($entries[] = $this->nodes[$id][0]) : ($exits[] = $this->nodes[$id][0]);
            }
            \array_push($sets, ...\array_map(
                static fn (array $set): LayoutRankSet => new LayoutRankSet(...$set),
                \array_filter(
                    [
                        [RankKind::Source, $entries],
                        [RankKind::Sink,   $exits],
                    ],
                    static fn (array $set): bool => [] !== $set[1],
                ),
            ));
        }

        return $sets;
    }

    /** The cluster a cluster's content stands in: its inner protection, itself when packed, the one inside its points' ranks when it has some. */
    private function holder(string $cluster): string
    {
        return match (true) {
            \array_key_exists($cluster, $this->packed) => $cluster,
            \array_key_exists($cluster, $this->bordered) => (\array_key_exists($cluster, $this->linked) ? self::WITHIN : self::BORDERED).$cluster,
            default => self::INNER.$cluster,
        };
    }
}
