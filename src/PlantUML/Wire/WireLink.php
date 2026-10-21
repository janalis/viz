<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

/**
 * A link of a wire diagram, as PlantUML's `WLinkHorizontal` and `WLinkVertical` keep one:
 * where it leaves, how far across — or down — it reaches, whether it is a bus, which ends
 * bear a head, its colour and its text.
 */
final readonly class WireLink
{
    /**
     * @param array{float, float} $start
     * @param float $destination the x it reaches across to, or the y it reaches down to
     * @param ?list<string> $label
     */
    public function __construct(
        public array $start,
        public float $destination,
        public bool $vertical,
        public bool $bus,
        public bool $forward,
        public bool $backward,
        public string $color,
        public ?array $label,
    ) {}
}
