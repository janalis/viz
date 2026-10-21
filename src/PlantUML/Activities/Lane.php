<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

/** A swimlane, as `|Lane|` or `swimlane Lane` declares it: its name, its background, and its title. */
final class Lane
{
    /** @param list<string> $title */
    public function __construct(
        public readonly string $name,
        public ?string $color,
        public array $title,
    ) {}
}
