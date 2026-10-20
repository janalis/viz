<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** A tile its flow does not leave, after `kill` or `detach`. */
final class FtileKilled extends AbstractFtile
{
    public function __construct(
        private readonly Ftile $tile,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        return $this->tile->geometry()->withoutOut();
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
    public function draw(Canvas $canvas): void
    {
        $this->tile->draw($canvas);
    }
}
