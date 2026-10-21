<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's wire diagrams, drawn natively. */
final class WireFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return WireReader::read($document, $commands);
    }
}
