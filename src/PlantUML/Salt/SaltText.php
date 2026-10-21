<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementText`: a line of text, `.` taking a line's room and showing nothing. */
final readonly class SaltText implements SaltElement
{
    private SaltLabel $label;

    public function __construct(
        public string $text,
    ) {
        $this->label = new SaltLabel([$text]);
    }

    #[\Override]
    public function size(): array
    {
        return $this->label->size();
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        return 0 !== $pass || '.' === $this->text ? [] : $this->label->marks($x, $y);
    }
}
