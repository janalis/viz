<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/** An atom 2 clear above and below, as PlantUML's `AtomWithMargin` sets a table or a tree. */
final readonly class CreoleMargin implements CreoleAtom
{
    public function __construct(
        public CreoleAtom $atom,
    ) {}

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->atom->size();

        return [$width, $height + 4];
    }

    #[\Override]
    public function altitude(): float
    {
        return $this->atom->altitude();
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        return $this->atom->marks($x, $y + 2);
    }
}
