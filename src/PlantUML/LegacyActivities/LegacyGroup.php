<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

/** A partition of a legacy activity diagram, drawn as a package: its title, colour and stereotype, and the partition it stands in. */
final class LegacyGroup
{
    public ?string $color = null;
    public ?string $stereotype = null;

    public function __construct(
        public readonly string $id,
        public readonly string $display,
        public readonly ?string $parent = null,
    ) {}
}
