<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** What every view of one canvas draws into: its shapes, and its arrows held back. */
final class CanvasStore
{
    /** @var list<Shape> */
    public array $shapes = [];
    /** @var list<Snake> */
    public array $snakes = [];
}
