<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/** A rule with nothing after its `=`, as PlantUML's `ETileEmpty`: no room, nothing drawn. */
final class EmptyTile extends EbnfTile
{
    #[\Override]
    public function width(): float
    {
        return 0.0;
    }

    #[\Override]
    public function h1(): float
    {
        return 0.0;
    }

    #[\Override]
    public function h2(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        return [];
    }
}
