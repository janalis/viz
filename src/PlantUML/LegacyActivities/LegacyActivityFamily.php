<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML activity diagrams in the legacy syntax, read and drawn natively. */
final readonly class LegacyActivityFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlLegacyActivity
    {
        return LegacyActivityReader::read($document, $commands);
    }
}
