<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A note beside one participant, or over one or two. */
final readonly class Note implements Step
{
    /** @param non-empty-list<string> $participants */
    public function __construct(
        public string $text,
        public NotePlacement $placement,
        public array $participants,
    ) {}
}
