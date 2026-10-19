<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** A note on a classifier, or floating when it has none. */
final readonly class Note
{
    public function __construct(
        public string $text,
        public ?string $for = null,
    ) {}
}
