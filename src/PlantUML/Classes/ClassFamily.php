<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML class diagrams, read and drawn natively. */
final readonly class ClassFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlClasses
    {
        return ClassReader::read($document, $commands);
    }
}
