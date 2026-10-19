<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\Source\Unsupported;

/** One kind of PlantUML diagram read natively, from the commands its lines matched. */
interface PlantUmlFamily
{
    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     */
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram;
}
