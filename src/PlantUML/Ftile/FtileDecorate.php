<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/**
 * A tile with what it says of itself changed: the arrow into it or out of it, the breaks it
 * holds, the arrows it draws besides itself — or moved right, as when a margin is put before it.
 */
final class FtileDecorate implements Ftile
{
    /**
     * @param ?list<FtileBreak> $weldings
     * @param list<Connection> $connections
     */
    public function __construct(
        public readonly Ftile $tile,
        private readonly ?LinkRendering $in = null,
        private readonly ?LinkRendering $out = null,
        private readonly ?array $weldings = null,
        private readonly array $connections = [],
    ) {}

    #[\Override]
    public function geometry(): Geometry
    {
        return $this->tile->geometry();
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $this->tile->draw($canvas);
        foreach ($this->connections as $connection) {
            $connection->draw($canvas);
        }
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
        return $this->in ?? $this->tile->inLink();
    }

    #[\Override]
    public function outLink(): LinkRendering
    {
        return $this->out ?? $this->tile->outLink();
    }

    #[\Override]
    public function weldings(): array
    {
        return $this->weldings ?? $this->tile->weldings();
    }
}
