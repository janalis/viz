<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Svek;

use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutCluster;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Model\Direction;

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

    /** @var array<string, array{string, float, float, ?string}> by id, which each carries, as a numeric one would not stay a string key */
    private array $nodes = [];
    /** @var array<string, array{string, ?string, float, float}> */
    private array $clusters = [];
    /** @var array<string, true> the clusters `together` packs, which svek protects with no clusters of its own */
    private array $packed = [];
    /** @var list<array{string, string, int, float, float, bool}> */
    private array $edges = [];

    public function node(string $id, float $width, float $height, ?string $cluster = null): void
    {
        $this->nodes[$id] = [$id, $width, $height, $cluster];
    }

    /** A cluster, with the size of its title, which it keeps room for above its content. */
    public function cluster(string $id, ?string $parent, float $titleWidth = 0.0, float $titleHeight = 0.0): void
    {
        $this->clusters[$id] = [$id, $parent, $titleWidth, $titleHeight];
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

    /** An edge, by the dashes of the link it stands for: one lays its ends side by side. Its index in the placement's routes. */
    public function edge(string $from, string $to, int $length, float $labelWidth = 0.0, float $labelHeight = 0.0, bool $constraint = true): int
    {
        $this->edges[] = [$from, $to, \max(0, $length - 1), $labelWidth, $labelHeight, $constraint];

        return \count($this->edges) - 1;
    }

    /** @return \Generator<int, null, mixed, Placement> */
    public function place(Direction $direction = Direction::TopBottom, float $nodeSeparation = 35.0, float $rankSeparation = 60.0): \Generator
    {
        $edges = [];
        foreach ($this->edges as $index => [$from, $to, $minimum, $labelWidth, $labelHeight, $constraint]) {
            $edges[] = new LayoutEdge($index, $from, $to, $minimum, constraint: $constraint, labelWidth: $labelWidth, labelHeight: $labelHeight);
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
            $clusters[] = new LayoutCluster(self::OUTER.$id, '', $holder, order: \count($clusters));
            $clusters[] = new LayoutCluster($id, '', self::OUTER.$id, minWidth: $titleWidth, labelHeight: $titleHeight, order: \count($clusters));
            $clusters[] = new LayoutCluster(self::INNER.$id, '', $id, order: \count($clusters));
        }
        $graph = new LayoutGraph($this->ordered(), $edges, $clusters, $direction, new Spacing($rankSeparation, $nodeSeparation, 4.0));
        $placing = new DotEngine()->place($graph);
        yield from $placing;
        $placement = $placing->getReturn();
        $layout = $placement->graph;
        $kept = \array_values(\array_filter($layout->clusters, fn (GraphCluster $cluster): bool => \array_key_exists($cluster->name, $this->clusters)));

        return new Placement(
            new GraphLayout($layout->width, $layout->height, $layout->nodes, $layout->edges, $kept),
            $placement->endpoints,
            $placement->directions,
            $placement->routes,
            $placement->labels,
        );
    }

    /** @return list<LayoutNode> the nodes at the top first, then cluster by cluster as they nest */
    private function ordered(): array
    {
        $ordered = [];
        foreach ([null, ...\array_column($this->clusters, 0)] as $cluster) {
            foreach ($this->nodes as [$id, $width, $height, $owner]) {
                if ($owner !== $cluster) {
                    continue;
                }
                $ordered[] = new LayoutNode($id, '', $width, $height, null === $owner ? null : $this->holder($owner), \count($ordered));
            }
        }

        return $ordered;
    }

    /** The cluster a cluster's content stands in: its inner protection, or itself when packed. */
    private function holder(string $cluster): string
    {
        return \array_key_exists($cluster, $this->packed) ? $cluster : self::INNER.$cluster;
    }
}
