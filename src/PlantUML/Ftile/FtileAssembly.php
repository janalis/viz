<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** One tile above another, their flows lined up. */
final class FtileAssembly extends AbstractFtile
{
    public function __construct(
        public readonly Ftile $tile1,
        public readonly Ftile $tile2,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        return $this->tile1->geometry()->appendBottom($this->tile2->geometry());
    }

    #[\Override]
    public function children(): array
    {
        return [$this->tile1, $this->tile2];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        $left = $this->geometry()->left;

        return $child === $this->tile1 ? [$left - $this->tile1->geometry()->left, 0.0] : [$left - $this->tile2->geometry()->left, $this->tile1->geometry()->height];
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        foreach ([$this->tile1, $this->tile2] as $tile) {
            [$dx, $dy] = $this->translateFor($tile);
            $tile->draw($canvas->moved($dx, $dy));
        }
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->tile1->inLink();
    }

    #[\Override]
    public function weldings(): array
    {
        return [...$this->tile1->weldings(), ...$this->tile2->weldings()];
    }
}
