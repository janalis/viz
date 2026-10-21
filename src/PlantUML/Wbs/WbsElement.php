<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

/**
 * An element of a work breakdown structure, as PlantUML's `WElement` keeps one: its text, its
 * depth, its shape, colour and stereotype, and its children on its left and on its right — a
 * child of the root on its left standing first among those on its right too.
 */
final class WbsElement
{
    /** @var list<WbsElement> */
    public array $left = [];
    /** @var list<WbsElement> */
    public array $right = [];

    /** @param list<string> $label a line each */
    public function __construct(
        public readonly array $label,
        public readonly int $level,
        public readonly WbsShape $shape,
        public readonly ?string $color,
        public readonly ?string $stereotype,
        public readonly ?self $parent = null,
    ) {}

    /**
     * A child of it, on a side.
     *
     * @param list<string> $label
     */
    public function child(array $label, int $level, WbsShape $shape, ?string $color, ?string $stereotype, WbsSide $side): self
    {
        $child = new self($label, $level, $shape, $color, $stereotype, $this);
        if (WbsSide::Left === $side && 1 === $level) {
            \array_unshift($this->right, $child);
        }
        if (WbsSide::Left === $side) {
            $this->left[] = $child;

            return $child;
        }
        $this->right[] = $child;

        return $child;
    }

    public function leaf(): bool
    {
        return [] === $this->left && [] === $this->right;
    }
}
