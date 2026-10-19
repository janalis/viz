<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** An entity and its attributes in order. */
final readonly class Entity
{
    /** @param list<Attribute> $attributes */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public array $attributes = [],
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
