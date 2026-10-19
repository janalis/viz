<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

use Janalis\Viz\Model\Direction;

/**
 * One state. With no region it is simple; with one it is composite, its substates inside;
 * with several its regions run concurrently.
 */
final readonly class State
{
    /**
     * @param list<string> $description lines drawn inside the state, under its name
     * @param list<Region> $regions
     */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public StateKind $kind = StateKind::Simple,
        public array $description = [],
        public array $regions = [],
        /** The direction a composite state lays out its substates in; null inherits. */
        public ?Direction $direction = null,
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }

    public function composite(): bool
    {
        return [] !== $this->regions;
    }
}
