<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * PlantUML's `NTetris`: each element in the leftmost columns its stages leave free, a grid
 * of columns by stages burned as they are taken.
 *
 * @template T of NwStaged
 */
final class NwTetris
{
    /** @var list<array{T, int}> each element and its column */
    private array $placed = [];
    /** @var array<string, true> */
    private array $burned = [];

    /** @param T $element */
    public function add(NwStaged $element): void
    {
        for ($column = 0; $column <= 100; ++$column) {
            if (!$this->free($element, $column)) {
                continue;
            }
            $this->placed[] = [$element, $column];
            foreach ($this->cells($element, $column) as $cell) {
                $this->burned[$cell] = true;
            }

            return;
        }

        throw new PlantUmlRefusal('The network diagram is too wide');
    }

    /** @return list<array{T, int}> */
    public function placed(): array
    {
        return $this->placed;
    }

    public function width(): int
    {
        return \array_reduce($this->placed, static fn (int $width, array $placed): int => \max($width, $placed[1] + $placed[0]->width()), initial: 0);
    }

    /** @param T $element */
    private function free(NwStaged $element, int $column): bool
    {
        foreach ($this->cells($element, $column) as $cell) {
            if (\array_key_exists($cell, $this->burned)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param T $element
     * @return list<string>
     */
    private function cells(NwStaged $element, int $column): array
    {
        $cells = [];
        for ($x = $column; $x < ($column + $element->width()); ++$x) {
            for ($y = $element->start(); $y <= $element->end(); ++$y) {
                $cells[] = $x.';'.$y;
            }
        }

        return $cells;
    }
}
