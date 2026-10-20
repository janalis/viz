<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Tiles side by side, left to right, as tall as the tallest, the flow through its middle. */
final class FtileForkInner extends AbstractFtile
{
    /** @param non-empty-list<Ftile> $tiles */
    public function __construct(
        public readonly array $tiles,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        $width = 0.0;
        $height = 0.0;
        foreach ($this->tiles as $tile) {
            $width += $tile->geometry()->width;
            $height = \max($height, $tile->geometry()->height);
        }

        return new Geometry($width, $height, $width / 2, 0.0, $height);
    }

    #[\Override]
    public function children(): array
    {
        return $this->tiles;
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        $x = 0.0;
        foreach ($this->tiles as $tile) {
            if ($tile === $child) {
                break;
            }
            $x += $tile->geometry()->width;
        }

        return [$x, 0.0];
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $x = 0.0;
        foreach ($this->tiles as $tile) {
            $tile->draw($canvas->moved($x, 0.0));
            $x += $tile->geometry()->width;
        }
    }
}
