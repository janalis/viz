<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** What most tiles share: a size measured once, no arrows of their own, no breaks, no tiles inside. */
abstract class AbstractFtile implements Ftile
{
    private ?Geometry $geometry = null;

    #[\Override]
    final public function geometry(): Geometry
    {
        return $this->geometry ??= $this->measure();
    }

    abstract protected function measure(): Geometry;

    #[\Override]
    public function children(): array
    {
        return [];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return [0.0, 0.0];
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return new LinkRendering();
    }

    #[\Override]
    public function outLink(): LinkRendering
    {
        return new LinkRendering();
    }

    #[\Override]
    public function weldings(): array
    {
        return [];
    }
}
