<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

/** An idea of a mind map, as PlantUML's `Idea` keeps one: its text, its depth, its shape, its colour and its stereotype, under its parent. */
final class MindMapIdea
{
    /** @var list<MindMapIdea> */
    public array $children = [];

    /** @param list<string> $label a line each */
    public function __construct(
        public readonly array $label,
        public readonly int $level,
        /** Whether it is drawn in a box, or as its text alone (`_`). */
        public readonly bool $boxed,
        public readonly ?string $color,
        public readonly ?string $stereotype,
        public readonly ?self $parent = null,
    ) {}

    /**
     * A child of it, at a depth.
     *
     * @param list<string> $label
     */
    public function child(array $label, int $level, bool $boxed, ?string $color, ?string $stereotype): self
    {
        $child = new self($label, $level, $boxed, $color, $stereotype, $this);
        $this->children[] = $child;

        return $child;
    }
}
