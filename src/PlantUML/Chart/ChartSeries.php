<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

/** One series of a chart's values, each at a category or at its own place across. */
final readonly class ChartSeries
{
    /**
     * @param list<float> $values
     * @param ?list<float> $xs each value's place across, when given as pairs
     * @param list<string> $stereotypes
     */
    public function __construct(
        public string $name,
        public ChartKind $kind,
        public array $values,
        public ?array $xs = null,
        public ?string $color = null,
        public bool $secondary = false,
        public bool $labels = false,
        public string $marker = 'circle',
        public array $stereotypes = [],
    ) {}
}
