<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** A step's tile whose own tiles may stand in other swimlanes, its arrows bending across them. */
final readonly class FtileSpanning implements Ftile
{
    public function __construct(
        public Ftile $tile,
    ) {}

    #[\Override]
    public function geometry(): Geometry
    {
        return $this->tile->geometry();
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $this->tile->draw($canvas->spanning());
    }

    #[\Override]
    public function children(): array
    {
        return [$this->tile];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return [0.0, 0.0];
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->tile->inLink();
    }

    #[\Override]
    public function outLink(): LinkRendering
    {
        return $this->tile->outLink();
    }

    #[\Override]
    public function weldings(): array
    {
        return $this->tile->weldings();
    }
}
