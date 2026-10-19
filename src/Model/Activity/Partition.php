<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** A labelled frame around consecutive steps. */
final readonly class Partition implements Step
{
    /** @param list<Step> $steps */
    public function __construct(
        public string $label,
        public array $steps = [],
    ) {}
}
