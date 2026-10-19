<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Source;

use Janalis\Viz\GraphViz\GraphParseException;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * `dot -Tjson0` read into the shared {@see Graph}. dot has already resolved the
 * whole language — defaults, attribute inheritance, `\N` labels, subgraph
 * membership — so this only maps names: shapes, arrowheads and line styles with
 * no shared equivalent become a box, an arrow and a solid line. Positions are
 * ignored. A subgraph that is not a cluster lends its nodes to its parent.
 */
final class DotGraphReader
{
    private const array SHAPES = [
        'circle' => Shape::Circle,
        'doublecircle' => Shape::Circle,
        'point' => Shape::Circle,
        'ellipse' => Shape::Ellipse,
        'oval' => Shape::Ellipse,
        'diamond' => Shape::Diamond,
        'hexagon' => Shape::Hexagon,
        'parallelogram' => Shape::Parallelogram,
        'cylinder' => Shape::Cylinder,
        'note' => Shape::Note,
        'tab' => Shape::Note,
    ];

    /** @throws GraphParseException when the JSON is not dot's */
    public static function read(string $json): Graph
    {
        try {
            // @mago-expect analysis:mixed-assignment -- Narrowed to an array below.
            $data = \json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GraphParseException('dot produced invalid JSON: '.$exception->getMessage(), previous: $exception);
        }
        if (!\is_array($data)) {
            throw new GraphParseException('dot produced a non-object JSON document');
        }
        $objects = self::list($data['objects'] ?? []);
        $count = \is_int($data['_subgraph_cnt'] ?? null) ? $data['_subgraph_cnt'] : 0;
        $names = [];
        $nodes = [];
        foreach (\array_slice($objects, offset: $count, preserve_keys: true) as $index => $object) {
            $name = self::string($object, 'name');
            $names[$index] = $name;
            $nodes[] = self::node($object, $name);
        }
        // An undirected graph's edges have no arrows unless an attribute asks for one.
        $dir = true === ($data['directed'] ?? true) ? 'forward' : 'none';
        $edges = [];
        foreach (self::list($data['edges'] ?? []) as $edge) {
            $edges[] = self::edge($edge, $names, $dir);
        }
        $children = [];
        foreach (\array_slice($objects, offset: 0, length: $count, preserve_keys: true) as $index => $subgraph) {
            foreach (self::ints($subgraph['subgraphs'] ?? []) as $child) {
                $children[$child] = $index;
            }
        }
        $roots = \array_values(\array_filter(
            \array_keys(\array_slice($objects, offset: 0, length: $count, preserve_keys: true)),
            static fn (int $index): bool => !\array_key_exists($index, $children),
        ));

        return new Graph(
            $nodes,
            $edges,
            self::clusters($objects, $roots, $names),
            Direction::tryFrom(\strtoupper(self::string($data, 'rankdir'))) ?? Direction::TopBottom,
            self::string($data, 'label'),
        );
    }

    /** @param array<array-key, mixed> $object */
    private static function node(array $object, string $name): Node
    {
        $label = self::string($object, 'label');
        $label = '' === $label || '\N' === $label ? null : \str_replace(['\N', '\n', '\l', '\r'], [$name, "\n", "\n", "\n"], $label);
        $styles = \explode(',', self::string($object, 'style'));
        $shape = self::SHAPES[self::string($object, 'shape')] ?? null;
        $fill = self::string($object, 'fillcolor');

        return new Node(
            $name,
            $label === $name ? null : $label,
            $shape ?? (\in_array('rounded', $styles, strict: true) ? Shape::Rounded : Shape::Box),
            '' !== $fill && \in_array('filled', $styles, strict: true) ? $fill : null,
            '' !== self::string($object, 'color') ? self::string($object, 'color') : null,
        );
    }

    /**
     * @param array<array-key, mixed> $edge
     * @param array<int, string>      $names
     */
    private static function edge(array $edge, array $names, string $defaultDir): Edge
    {
        $tail = \is_int($edge['tail'] ?? null) ? $edge['tail'] : -1;
        $head = \is_int($edge['head'] ?? null) ? $edge['head'] : -1;
        if (!\array_key_exists($tail, $names) || !\array_key_exists($head, $names)) {
            throw new GraphParseException('edge references a missing node');
        }
        $dir = self::string($edge, 'dir');
        if ('' === $dir) {
            $dir = $defaultDir;
        }

        return new Edge(
            $names[$tail],
            $names[$head],
            self::string($edge, 'label'),
            \in_array($dir, ['forward', 'both'], strict: true) ? self::arrow(self::string($edge, 'arrowhead')) : ArrowHead::None,
            \in_array($dir, ['back', 'both'], strict: true) ? self::arrow(self::string($edge, 'arrowtail')) : ArrowHead::None,
            match (\explode(',', self::string($edge, 'style'))[0]) {
                'dashed' => LineStyle::Dashed,
                'dotted' => LineStyle::Dotted,
                'bold' => LineStyle::Bold,
                default => LineStyle::Solid,
            },
        );
    }

    private static function arrow(string $name): ArrowHead
    {
        return match (true) {
            'none' === $name => ArrowHead::None,
            \str_ends_with($name, 'dot') => ArrowHead::Circle,
            \str_ends_with($name, 'tee') => ArrowHead::Cross,
            default => ArrowHead::Arrow,
        };
    }

    /**
     * @param list<array<array-key, mixed>> $objects
     * @param list<int>                     $indices subgraphs at this level
     * @param array<int, string>            $names
     * @return list<Cluster>
     */
    private static function clusters(array $objects, array $indices, array $names): array
    {
        $clusters = [];
        foreach ($indices as $index) {
            $subgraph = $objects[$index];
            $inner = self::ints($subgraph['subgraphs'] ?? []);
            if (!\str_starts_with(self::string($subgraph, 'name'), 'cluster')) {
                // Not drawn as a box: its own clusters surface at this level.
                \array_push($clusters, ...self::clusters($objects, $inner, $names));
                continue;
            }
            $nested = [];
            foreach ($inner as $child) {
                foreach (self::descendants($objects, $child) as $node) {
                    $nested[$node] = true;
                }
            }
            $own = \array_values(\array_filter(self::ints($subgraph['nodes'] ?? []), static fn (int $node): bool => !\array_key_exists($node, $nested) && \array_key_exists($node, $names)));
            $clusters[] = new Cluster(
                self::string($subgraph, 'name'),
                self::string($subgraph, 'label'),
                \array_map(static fn (int $node): string => $names[$node], $own),
                self::clusters($objects, $inner, $names),
            );
        }

        return $clusters;
    }

    /**
     * Nodes a subgraph holds that belong to one of its clusters, at any depth.
     *
     * @param list<array<array-key, mixed>> $objects
     * @return list<int>
     */
    private static function descendants(array $objects, int $index): array
    {
        $subgraph = $objects[$index] ?? [];

        return (
            \str_starts_with(self::string($subgraph, 'name'), 'cluster')
                ? self::ints($subgraph['nodes'] ?? [])
                : \array_merge([], ...\array_map(static fn (int $child): array => self::descendants($objects, $child), self::ints($subgraph['subgraphs'] ?? [])))
        );
    }

    /** @return list<array<array-key, mixed>> */
    private static function list(mixed $value): array
    {
        // @mago-expect lint:inline-variable-return -- The @var is what tells the analyzer the filter narrowed it.
        /** @var list<array<array-key, mixed>> $list array_filter keeps only arrays */
        $list = \is_array($value) ? \array_values(\array_filter($value, \is_array(...))) : [];

        return $list;
    }

    /** @return list<int> */
    private static function ints(mixed $value): array
    {
        // @mago-expect lint:inline-variable-return -- The @var is what tells the analyzer the filter narrowed it.
        /** @var list<int> $ints array_filter keeps only integers */
        $ints = \is_array($value) ? \array_values(\array_filter($value, \is_int(...))) : [];

        return $ints;
    }

    /** @param array<array-key, mixed> $object */
    private static function string(array $object, string $key): string
    {
        return \is_string($object[$key] ?? null) ? $object[$key] : '';
    }
}
