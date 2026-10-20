<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** An arrow a tile draws between the tiles it holds. */
interface Connection
{
    public function draw(Canvas $canvas): void;
}
