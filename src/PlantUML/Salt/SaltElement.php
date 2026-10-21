<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\Draw\Mark;

/**
 * A widget of a salt diagram, as PlantUML's salt `Element` is one: the room it would take,
 * and its marks at a top left, given the room it has, in one of two passes — 0 for the
 * widgets themselves, 1 for the menus that open over them.
 */
interface SaltElement
{
    /** @return array{float, float} */
    public function size(): array;

    /** @return list<Mark> */
    public function marks(float $x, float $y, int $pass, float $width, float $height): array;
}
