<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** One lifeline. */
final readonly class Participant
{
    public function __construct(
        public string $id,
        public ?string $label = null,
        public ParticipantKind $kind = ParticipantKind::Participant,
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
