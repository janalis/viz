<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/** Text in one font, as PlantUML's `AtomText`: a line high, 10 at least, maybe with room either side, as a list's number. */
final readonly class CreoleWord implements CreoleAtom
{
    public function __construct(
        public string $text,
        public CreoleFont $font,
        private float $left = 0.0,
        private float $right = 0.0,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->left + $this->font->width($this->text) + $this->right, \max(10.0, $this->font->line())];
    }

    #[\Override]
    public function altitude(): float
    {
        return $this->font->space();
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        return $this->font->marks($this->text, $x + $this->left, $y + $this->font->line() - $this->font->descent());
    }
}
