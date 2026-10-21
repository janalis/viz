<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Board;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's boards, drawn natively. */
final class BoardFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return BoardReader::read($document, $commands);
    }
}
