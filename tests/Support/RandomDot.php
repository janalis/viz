<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

/**
 * Seeded random DOT sources that use the grammar's every turn: defaults at any depth,
 * clusters and plain subgraphs, reopened subgraphs, edge chains through subgraphs, ports,
 * strict and undirected graphs, quoted and concatenated ids. Comparing how they read
 * natively against dot's own JSON pins the language's resolution rules.
 */
final class RandomDot
{
    private const array SHAPES = ['box', 'ellipse', 'circle', 'diamond', 'note', 'cylinder', 'hexagon', 'tab', 'point', 'plaintext'];
    private const array STYLES = ['dashed', 'dotted', 'bold', 'solid', 'rounded', 'filled', 'rounded,filled'];
    private const array ARROWS = ['normal', 'none', 'dot', 'odot', 'tee', 'empty', 'diamond', 'odiamond', 'vee', 'inv'];
    private const array COLORS = ['red', 'blue', '#00ff00', 'black', 'gray'];

    private int $subgraphs = 0;
    private int $depth = 0;

    private function __construct(
        private readonly \Random\Randomizer $random,
        private readonly bool $directed,
    ) {}

    public static function source(int $seed): string
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $directed = 0 !== $random->getInt(0, 3);
        $generator = new self($random, $directed);
        $head = ($random->getInt(0, 4) === 0 ? 'strict ' : '').($directed ? 'digraph' : 'graph').' G'.$seed;

        return $head." {\n".$generator->statements($random->getInt(4, 14))."}\n";
    }

    private function statements(int $count): string
    {
        $lines = '';
        for ($index = 0; $index < $count; ++$index) {
            $lines .= \str_repeat('  ', \max(0, $this->depth) + 1).$this->statement().";\n";
        }

        return $lines;
    }

    private function statement(): string
    {
        return match ($this->random->getInt(0, 9)) {
            0 => 'node ['.$this->attributes('node').']',
            1 => 'edge ['.$this->attributes('edge').']',
            2 => 0 === $this->random->getInt(0, 1) ? 'graph [label="L'.$this->random->getInt(0, 9).'", color="'.$this->pick(self::COLORS).'"]' : 'rankdir='.$this->pick(['LR', 'TB', 'BT', 'RL']),
            3, 4 => $this->depth < 3 ? $this->subgraph() : $this->node(),
            5, 6, 7 => $this->chain(),
            default => $this->node(),
        };
    }

    private function node(): string
    {
        return $this->id().(0 === $this->random->getInt(0, 2) ? ' ['.$this->attributes('node').']' : '');
    }

    private function chain(): string
    {
        $operator = $this->directed ? ' -> ' : ' -- ';
        $parts = [];
        $length = $this->random->getInt(2, 4);
        for ($index = 0; $index < $length; ++$index) {
            $parts[] =
                0 === $this->random->getInt(0, 4) && $this->depth < 3 ? '{ '.$this->id().' '.$this->id().' }' : $this->id().(0 === $this->random->getInt(0, 6) ? ':p'.$this->random->getInt(0, 2) : '');
        }

        return \implode($operator, $parts).(0 === $this->random->getInt(0, 1) ? ' ['.$this->attributes('edge').']' : '');
    }

    private function subgraph(): string
    {
        $name = match ($this->random->getInt(0, 3)) {
            0 => '',
            1 => 'subgraph s'.$this->random->getInt(0, $this->subgraphs),
            default => 'subgraph cluster_'.$this->random->getInt(0, $this->subgraphs++),
        };
        ++$this->depth;
        $body = $this->statements($this->random->getInt(1, 5));
        --$this->depth;

        return ('' === $name ? '' : $name.' ')."{\n".$body.\str_repeat('  ', \max(0, $this->depth) + 1).'}';
    }

    private function attributes(string $kind): string
    {
        $pairs = [];
        $count = $this->random->getInt(1, 3);
        for ($index = 0; $index < $count; ++$index) {
            $pairs[] = 'node' === $kind
                ? match ($this->random->getInt(0, 4)) {
                    0 => 'shape='.$this->pick(self::SHAPES),
                    1 => 'style="'.$this->pick(self::STYLES).'"',
                    2 => 'fillcolor="'.$this->pick(self::COLORS).'"',
                    3 => 'color="'.$this->pick(self::COLORS).'"',
                    default => 'label="'.$this->pick(['\N', 'x\ny', 'Label', '', 'a\lb']).'"',
                }
                : match ($this->random->getInt(0, 4)) {
                    0 => 'style='.$this->pick(['dashed', 'dotted', 'bold', 'solid']),
                    1 => 'arrowhead='.$this->pick(self::ARROWS),
                    2 => 'arrowtail='.$this->pick(self::ARROWS),
                    3 => 'dir='.$this->pick(['forward', 'back', 'both', 'none']),
                    default => 'label="e'.$this->random->getInt(0, 9).'"',
                };
        }

        return \implode(', ', $pairs);
    }

    private function id(): string
    {
        $number = $this->random->getInt(0, 9);

        return match ($this->random->getInt(0, 5)) {
            0 => '"n'.$number.'"',
            1 => '"n" + "'.$number.'"',
            default => 'n'.$number,
        };
    }

    /** @param non-empty-list<string> $values */
    private function pick(array $values): string
    {
        return $values[$this->random->getInt(0, \count($values) - 1)];
    }
}
