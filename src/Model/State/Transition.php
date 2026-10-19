<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/** A transition between two states by id, at any depth. */
final readonly class Transition
{
    public function __construct(
        public string $from,
        public string $to,
        public string $label = '',
    ) {}
}
