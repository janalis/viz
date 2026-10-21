<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/**
 * A network of a network diagram, as PlantUML's `Network` keeps one: its name, or the
 * description that stands for it, its address and colour, whether it is drawn — a network
 * PlantUML makes up to join servers is not — whether it runs the whole width, its stage and
 * the one before it; where it is drawn, once laid out.
 */
final class NwNetwork
{
    public ?string $description = null;
    public ?string $color = null;
    public ?string $address = null;
    public bool $visible = true;
    public bool $fullWidth = false;
    public float $y = 0.0;
    public float $xmin = 0.0;
    public float $xmax = 0.0;

    public function __construct(
        public readonly string $name,
        public readonly int $stage,
        /** The stage before its own, null for the first. */
        public readonly ?int $up,
    ) {}

    public function displayName(): string
    {
        return $this->description ?? $this->name;
    }

    /** As PlantUML's `magicDelta`: how far its links stand off a server's middle, 2 either way by its stage, none when it is not drawn. */
    public function shift(): float
    {
        return match (true) {
            !$this->visible => 0.0,
            0 === ($this->stage % 2) => 2.0,
            default => -2.0,
        };
    }
}
