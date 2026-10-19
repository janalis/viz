<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A labelled divider across every lifeline, or a delay when $delay. */
final readonly class Separator implements Step
{
    public function __construct(
        public string $label = '',
        public bool $delay = false,
    ) {}
}
