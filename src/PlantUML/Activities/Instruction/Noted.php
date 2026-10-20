<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities\Instruction;

use Janalis\Viz\PlantUML\Activities\PositionedNote;

/** A step notes may stand beside. */
abstract class Noted
{
    /** @var list<PositionedNote> */
    public array $notes = [];

    public function addNote(PositionedNote $note): void
    {
        $this->notes[] = $note;
    }
}
