<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's charts, drawn natively. */
final class ChartFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return ChartReader::read($document, $commands);
    }
}
