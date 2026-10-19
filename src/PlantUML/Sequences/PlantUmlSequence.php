<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\Source\Unsupported;

/** A PlantUML sequence diagram read natively. */
final readonly class PlantUmlSequence implements PlantUmlDiagram
{
    /**
     * @param list<SequenceParticipant> $participants as they stand, left to right
     * @param list<SequenceEvent> $events in time
     * @param list<SequenceBox> $boxes
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $participants,
        public array $events,
        public array $boxes = [],
        public bool $footbox = true,
        public bool $autonumber = false,
    ) {}

    #[\Override]
    public function model(): SequenceDiagram
    {
        return SequenceModel::of($this);
    }

    /** @throws Unsupported until teoz, PlantUML's sequence layout, is drawn natively */
    #[\Override]
    public function drawing(): \Generator
    {
        yield null;

        throw new Unsupported('The native PlantUML renderer does not draw sequence diagrams yet');
    }
}
