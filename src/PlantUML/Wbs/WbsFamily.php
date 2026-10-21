<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's work breakdown structures, drawn natively. */
final class WbsFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return WbsReader::read($document, $commands);
    }
}
