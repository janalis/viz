<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML activity diagrams in their newer syntax, read natively. */
final readonly class ActivityFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlActivity
    {
        return ActivityReader::read($document, $commands);
    }
}
