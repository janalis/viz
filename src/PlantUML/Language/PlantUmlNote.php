<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

/** A note: floating, beside an entity, or on a link, in any diagram that has them. */
final readonly class PlantUmlNote
{
    /** @param list<string> $lines */
    public function __construct(
        public string $id,
        public array $lines,
        public ?string $entity = null,
        public string $position = 'right',
        public ?int $link = null,
        public ?string $color = null,
        public ?string $group = null,
    ) {}
}
