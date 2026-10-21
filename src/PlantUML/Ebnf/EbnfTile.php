<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;

/**
 * A part of a railroad as PlantUML's `ETile` lays one out: as wide as it is, its line `h1`
 * down from its top and `h2` up from its bottom, drawn from its top left.
 */
abstract class EbnfTile
{
    abstract public function width(): float;

    abstract public function h1(): float;

    abstract public function h2(): float;

    /** @return list<Mark> */
    abstract public function marks(float $x, float $y, EbnfPen $pen): array;

    public function height(): float
    {
        return $this->h1() + $this->h2();
    }

    /** A comment written over it, which PlantUML ignores but where a tile shows it. */
    public function commentAbove(string $comment): void {}

    public function commentBelow(string $comment): void {}

    /** What it counts, as `3 * x` writes a count before the term it repeats. */
    public function repetitionLabel(): string
    {
        return '?';
    }
}
