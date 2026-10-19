<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A labelled frame around neighbouring participants. */
final readonly class Box
{
    /** @param list<string> $participants */
    public function __construct(
        public string $label = '',
        public array $participants = [],
        /** A CSS color, or null for the language's default. */
        public ?string $color = null,
    ) {}
}
