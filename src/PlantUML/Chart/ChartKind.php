<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

/** How a chart draws a series, as PlantUML's `ChartSeries.SeriesType` names it, and the style it takes. */
enum ChartKind: string
{
    case Bar = 'bar';
    case Line = 'line';
    case Area = 'area';
    case Scatter = 'scatter';
}
