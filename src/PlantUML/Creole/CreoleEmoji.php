<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\PlantUML\Draw\PlantUmlEmoji;

/** An emoji, as PlantUML's `AtomEmoji`: its 36 by 36 view scaled by its scale and its font's size over 24, its foot 3 of its 36 above the line's. */
final readonly class CreoleEmoji implements CreoleAtom
{
    private float $factor;

    public function __construct(
        private string $code,
        float $scale,
        float $size,
        /** The colour its colours are shaded towards, or null for its own. */
        private ?string $forced,
    ) {
        $this->factor = ($scale * $size) / 24;
    }

    #[\Override]
    public function size(): array
    {
        return [36 * $this->factor, 36 * $this->factor];
    }

    #[\Override]
    public function altitude(): float
    {
        return -3 * $this->factor;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        return PlantUmlEmoji::marks($this->code, $x, $y, 36 * $this->factor, $this->forced);
    }
}
