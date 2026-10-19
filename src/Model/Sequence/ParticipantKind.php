<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** How a participant is drawn at the head of its lifeline. */
enum ParticipantKind: string
{
    case Participant = 'participant';
    case Actor = 'actor';
    case Boundary = 'boundary';
    case Control = 'control';
    case Entity = 'entity';
    case Database = 'database';
    case Collections = 'collections';
    case Queue = 'queue';
}
