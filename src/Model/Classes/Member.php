<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** A field, or a method when it has parameters, even none. */
final readonly class Member
{
    public function __construct(
        public string $name,
        public Visibility $visibility = Visibility::Unspecified,
        /** A field's type, or a method's return type; null when unwritten. */
        public ?string $type = null,
        /** A method's parameters as written; null for a field. */
        public ?string $parameters = null,
        public bool $static = false,
        public bool $abstract = false,
    ) {}

    public function method(): bool
    {
        return null !== $this->parameters;
    }
}
