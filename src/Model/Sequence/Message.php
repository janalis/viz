<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A message from one participant to another, or to itself; a dashed one is a reply. */
final readonly class Message implements Step
{
    public function __construct(
        public string $from,
        public string $to,
        public string $label = '',
        public MessageHead $head = MessageHead::Filled,
        public bool $dashed = false,
        /** Whether the sending end bears the same head. */
        public bool $both = false,
    ) {}
}
