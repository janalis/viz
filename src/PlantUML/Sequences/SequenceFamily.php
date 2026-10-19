<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML sequence diagrams, read natively. */
final readonly class SequenceFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlSequence
    {
        return SequenceReader::read($document, $commands);
    }
}
