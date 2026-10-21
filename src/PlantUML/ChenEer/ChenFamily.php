<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's Chen EER diagrams, drawn natively. */
final class ChenFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return ChenReader::read($document, $commands);
    }
}
