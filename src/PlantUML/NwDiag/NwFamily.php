<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's network diagrams, drawn natively. */
final class NwFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return NwReader::read($document, $commands);
    }
}
