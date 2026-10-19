<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/**
 * Builds a {@see DotGraph} the way the DOT language resolves one, as its documentation
 * states: a default set by a `graph`, `node` or `edge` statement, or by a bare `name=value`
 * assignment, holds for objects of that kind defined afterwards in its scope and in the
 * subgraphs inside it that set no default of their own, never for earlier ones; a new
 * subgraph starts with its parent's own graph attributes as they stand. A node mentioned in a subgraph belongs
 * to it and to every subgraph around it; an edge between subgraphs joins every node of
 * one to every node of the other. A strict graph keeps one edge per pair of nodes, the
 * later attributes updating it.
 */
final class DotGraphBuilder
{
    private DotGraph $graph;
    /** @var list<DotScope> */
    private array $scopes = [];
    /** @var array<int, DotScope> each subgraph's defaults, kept for when it is opened again */
    private array $kept = [];
    /** @var array<string, int> each edge's index by its ends, in a strict graph */
    private array $pairs = [];
    private int $anonymous = 0;

    public function begin(string $name, bool $directed, bool $strict): void
    {
        $this->graph = new DotGraph($name, $directed, $strict);
        $this->scopes = [new DotScope(null)];
    }

    /**
     * A `graph`, `node` or `edge` attribute statement, or a bare assignment as `graph`.
     *
     * @param list<array{string, string, bool}> $attributes name, value, and whether the value is HTML
     */
    public function defaults(string $kind, array $attributes): void
    {
        $scope = $this->scope();
        foreach ($attributes as [$name, $value, $html]) {
            match ($kind) {
                'node' => $scope->node[$name] = [$value, $html],
                'edge' => $scope->edge[$name] = [$value, $html],
                default => $this->graphAttribute($scope, $name, [$value, $html]),
            };
        }
    }

    /**
     * A node statement, or a node an edge names: created on first mention with the defaults
     * then in force, made a member of the current subgraphs, then given its own attributes.
     *
     * @param list<array{string, string, bool}> $attributes
     */
    public function node(string $name, int $offset, array $attributes = []): void
    {
        if (!\array_key_exists($name, $this->graph->nodes)) {
            $node = new DotNode($name, offset: $offset);
            foreach ($this->scope()->defaults('node') as $attribute => $value) {
                self::set($node, $attribute, $value);
            }
            $this->graph->nodes[$name] = $node;
        }
        $this->join($name);
        foreach ($attributes as [$attribute, $value, $html]) {
            self::set($this->graph->nodes[$name], $attribute, [$value, $html]);
        }
    }

    /**
     * An edge between every tail and every head, with the edge defaults in force.
     *
     * @param list<array{string, ?string}> $tails node names, each with its port
     * @param list<array{string, ?string}> $heads
     * @param list<array{string, string, bool}> $attributes
     */
    public function edges(array $tails, array $heads, int $offset, array $attributes): void
    {
        foreach ($tails as [$tail, $tailPort]) {
            foreach ($heads as [$head, $headPort]) {
                $edge = $this->edge($tail, $head, $offset);
                foreach ($attributes as [$attribute, $value, $html]) {
                    self::set($edge, $attribute, [$value, $html]);
                }
                foreach (['tailport' => $tailPort, 'headport' => $headPort] as $attribute => $port) {
                    if (null === $port) {
                        continue;
                    }
                    self::set($edge, $attribute, [$port, false]);
                }
            }
        }
    }

    /** Opens a subgraph, or the one of that name in the current scope again; it inherits the defaults in force when first opened. */
    public function open(?string $name, int $offset): DotSubgraph
    {
        $parent = $this->scope();
        $siblings = null === $parent->subgraph ? $this->graph->subgraphs : $parent->subgraph->subgraphs;
        $existing = null === $name ? null : \array_find($siblings, static fn (DotSubgraph $subgraph): bool => $subgraph->name === $name);
        if (null !== $existing) {
            $this->scopes[] = $this->kept[\spl_object_id($existing)];

            return $existing;
        }
        $subgraph = new DotSubgraph($name ?? '%'.$this->anonymous++, $parent->subgraph, offset: $offset);
        // A subgraph starts with its parent's own graph attributes as they stand.
        $source = $parent->subgraph ?? $this->graph;
        foreach ($source->attributes as $attribute => $value) {
            self::set($subgraph, $attribute, [$value, \array_key_exists($attribute, $source->html)]);
        }
        if (null === $parent->subgraph) {
            $this->graph->subgraphs[] = $subgraph;
        }
        if (null !== $parent->subgraph) {
            $parent->subgraph->subgraphs[] = $subgraph;
        }
        $scope = new DotScope($subgraph, $parent);
        $this->kept[\spl_object_id($subgraph)] = $scope;
        $this->scopes[] = $scope;

        return $subgraph;
    }

    public function close(): void
    {
        \array_pop($this->scopes);
    }

    /**
     * @param array<array-key, true> $set
     * @return list<string>
     */
    public function ordered(array $set): array
    {
        return $this->graph->ordered($set);
    }

    public function finish(): DotGraph
    {
        return $this->graph;
    }

    /** @param array{string, bool} $value the value, and whether it is HTML */
    private function graphAttribute(DotScope $scope, string $name, array $value): void
    {
        self::set($scope->subgraph ?? $this->graph, $name, $value);
    }

    private function edge(string $tail, string $head, int $offset): DotEdge
    {
        $this->node($tail, $offset);
        $this->node($head, $offset);
        $pair = $this->graph->directed || \strcmp($tail, $head) <= 0 ? $tail."\0".$head : $head."\0".$tail;
        if ($this->graph->strict && \array_key_exists($pair, $this->pairs)) {
            return $this->graph->edges[$this->pairs[$pair]];
        }
        $edge = new DotEdge($tail, $head, offset: $offset);
        foreach ($this->scope()->defaults('edge') as $attribute => $value) {
            self::set($edge, $attribute, $value);
        }
        $this->pairs[$pair] = \count($this->graph->edges);
        $this->graph->edges[] = $edge;

        return $edge;
    }

    private function join(string $node): void
    {
        foreach ($this->scopes as $scope) {
            if (null === $scope->subgraph) {
                continue;
            }
            $scope->subgraph->nodes[$node] = true;
        }
    }

    private function scope(): DotScope
    {
        return $this->scopes[\count($this->scopes) - 1];
    }

    /** @param array{string, bool} $value the value, and whether it is HTML */
    private static function set(DotGraph|DotNode|DotEdge|DotSubgraph $object, string $name, array $value): void
    {
        $object->attributes[$name] = $value[0];
        if ($value[1]) {
            $object->html[$name] = true;

            return;
        }
        unset($object->html[$name]);
    }
}
