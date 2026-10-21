<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Flow;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's flow diagrams, drawn natively. */
final class FlowFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return FlowReader::read($document, $commands);
    }
}
