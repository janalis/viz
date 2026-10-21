<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Board;

/**
 * A card of a board, as PlantUML's `BNode` keeps one: its text, its stage — 0 for an
 * activity, one per `+` for a release under it — the cards under it, and the column it
 * stands in, a card its first child's, each later child a column further right.
 */
final class BoardCard
{
    /** @var list<self> */
    public array $children = [];
    public int $column = 0;

    public function __construct(
        public readonly string $text,
        public readonly int $stage,
        public readonly ?self $parent = null,
    ) {}

    /** As PlantUML's `computeX`, from a count of columns taken so far; the count when done. */
    public function place(int $column): int
    {
        $this->column = $column;
        foreach ($this->children as $index => $child) {
            $column = $child->place($index > 0 ? $column + 1 : $column);
        }

        return $column;
    }

    /** @return list<self> it and the cards under it, depth first */
    public function all(): array
    {
        return [$this, ...\array_merge(...\array_map(static fn (self $child): array => $child->all(), $this->children))];
    }
}
