<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

/** A system boundary around actors and use cases, by id. */
final readonly class Boundary
{
    /** @param list<string> $members */
    public function __construct(
        public string $id,
        public string $label = '',
        public array $members = [],
    ) {}

    public function text(): string
    {
        return '' === $this->label ? $this->id : $this->label;
    }
}
