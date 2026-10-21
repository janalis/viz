<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's regex diagrams, drawn natively. */
final class RegexFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return RegexReader::read($document, $commands);
    }
}
