<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** A tile of PlantUML's activity layout: a step or a block of steps, its size, and how it draws. */
interface Ftile
{
    public function geometry(): Geometry;

    public function draw(Canvas $canvas): void;

    /** @return list<Ftile> the tiles it places, to find where they stand */
    public function children(): array;

    /** @return array{float, float} where it places one of its tiles */
    public function translateFor(Ftile $child): array;

    /** The arrow into it, as written before it. */
    public function inLink(): LinkRendering;

    /** The arrow out of it, as written at the end of its block. */
    public function outLink(): LinkRendering;

    /** @return list<FtileBreak> the breaks inside it, which leave its loop */
    public function weldings(): array;
}
