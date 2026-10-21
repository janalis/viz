<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's salt wireframes, drawn natively. */
final class SaltFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return SaltReader::read($document, $commands);
    }
}
