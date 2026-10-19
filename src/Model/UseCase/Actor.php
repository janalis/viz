<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

/** Someone or something outside the system that takes part in its use cases. */
final readonly class Actor
{
    public function __construct(
        public string $id,
        public ?string $label = null,
        public ?string $stereotype = null,
        /** A business actor, drawn with a slash. */
        public bool $business = false,
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
