<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML component, deployment and use case diagrams, read and drawn natively. */
final readonly class DescriptionFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDescriptions
    {
        return DescriptionReader::read($document, $commands);
    }
}
