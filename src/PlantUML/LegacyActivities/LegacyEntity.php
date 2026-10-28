<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

/**
 * An entity of a legacy activity diagram, as PlantUML creates it: an `activity`, the `start`
 * and an `end`, a `branch` an `if` makes, a synchronization `bar`, or a `note` — its text, its
 * background and stereotype as written, and the partition it was created in.
 */
final class LegacyEntity
{
    public ?string $color = null;
    public ?string $stereotype = null;
    /** Laid out but not drawn, nor its links, as `hide` hides it. */
    public bool $hidden = false;

    /** @param list<string> $display */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public array $display,
        public readonly ?string $group = null,
    ) {}
}
