<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A mind map, as PlantUML's `MindMap` keeps one: its root twice, once for the ideas on its
 * right — or below — and once for those on its left — or above — each idea under the last
 * of the depth above it on its side, depths counted in steps of the first one's.
 */
final class MindMap
{
    public readonly MindMapIdea $right;
    public readonly MindMapIdea $left;
    private MindMapIdea $lastRight;
    private MindMapIdea $lastLeft;
    private int $multiplier = 0;

    /** @param list<string> $label */
    public function __construct(array $label, bool $boxed, ?string $color, ?string $stereotype)
    {
        $this->right = new MindMapIdea($label, 0, $boxed, $color, $stereotype);
        $this->left = new MindMapIdea($label, 0, $boxed, $color, $stereotype);
        $this->lastRight = $this->right;
        $this->lastLeft = $this->left;
    }

    /**
     * An idea at a depth past the root's.
     *
     * @param list<string> $label
     * @throws PlantUmlRefusal where PlantUML fails: a depth that is no step of the first, or more than one past the last idea's
     */
    public function add(int $level, array $label, bool $boxed, ?string $color, ?string $stereotype, MindMapSide $side): void
    {
        $this->multiplier = 0 === $this->multiplier ? $level : $this->multiplier;
        if (0 !== ($level % $this->multiplier)) {
            throw new PlantUmlRefusal('Bad indentation');
        }
        $level = \intdiv($level, $this->multiplier);
        $last = MindMapSide::Right === $side ? $this->lastRight : $this->lastLeft;
        if ($level > ($last->level + 1)) {
            throw new PlantUmlRefusal('error42L');
        }
        $parent = $last;
        for ($up = $last->level - $level + 1; $up > 0; --$up) {
            $parent = $parent->parent ?? $parent;
        }
        $idea = $parent->child($label, $level, $boxed, $color, $stereotype);
        if (MindMapSide::Right === $side) {
            $this->lastRight = $idea;

            return;
        }
        $this->lastLeft = $idea;
    }
}
