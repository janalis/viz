<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

/** Text a chart writes at a point, a category's or a number's across and a number's up, maybe with an arrow to it. */
final readonly class ChartAnnotation
{
    public function __construct(
        public string $text,
        public string|float $x,
        public float $y,
        public bool $arrow,
    ) {}
}
