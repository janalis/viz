<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;

/** A label as DOT sets it, plain text or HTML: its size, and its marks centred on a point, in points. */
interface DotLabel
{
    public function empty(): bool;

    /** @return array{float, float} width and height in points */
    public function size(): array;

    /** @return list<Mark> */
    public function marks(float $x, float $y): array;

    /**
     * The box each port the label names takes, the label centred on a point.
     *
     * @return array<string, array{float, float, float, float}> left, top, right, bottom
     */
    public function ports(float $x, float $y): array;
}
