<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

/** Something the system does for its actors. */
final readonly class UseCase
{
    public function __construct(
        public string $id,
        public ?string $label = null,
        public ?string $stereotype = null,
        /** A business use case, drawn with a slash. */
        public bool $business = false,
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
