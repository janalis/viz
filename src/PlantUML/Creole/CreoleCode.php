<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/** Lines between `<code>` and `</code>`, as PlantUML's `StripeCode`: as written, one under another, in the monospaced font. */
final class CreoleCode implements CreoleAtom
{
    /** @var list<string> */
    public array $lines = [];
    public bool $ended = false;

    public function __construct(
        private readonly CreoleFont $font,
    ) {}

    #[\Override]
    public function size(): array
    {
        $width = 0.0;
        foreach ($this->lines as $line) {
            $width = \max($width, $this->font->width($line));
        }

        return [$width, \count($this->lines) * $this->font->line()];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->lines as $line) {
            $y += $this->font->line();
            \array_push($marks, ...$this->font->marks($line, $x, $y - $this->font->descent()));
        }

        return $marks;
    }
}
