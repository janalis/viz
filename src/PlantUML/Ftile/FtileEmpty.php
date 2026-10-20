<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Room of a size the flow goes straight through, nothing drawn. */
class FtileEmpty extends AbstractFtile
{
    public function __construct(
        private readonly float $width = 0.0,
        private readonly float $height = 0.0,
    ) {}

    #[\Override]
    protected function measure(): Geometry
    {
        return new Geometry($this->width, $this->height, $this->width / 2, 0.0, $this->height);
    }

    #[\Override]
    public function draw(Canvas $canvas): void {}
}
