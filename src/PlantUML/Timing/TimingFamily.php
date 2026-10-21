<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's timing diagrams, drawn natively. */
final class TimingFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return TimingReader::read($document, $commands);
    }
}
