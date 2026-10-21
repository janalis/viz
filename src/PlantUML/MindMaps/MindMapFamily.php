<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's mind maps, drawn natively. */
final class MindMapFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return MindMapReader::read($document, $commands);
    }
}
