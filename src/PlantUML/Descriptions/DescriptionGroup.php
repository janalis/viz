<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

/** A container of a description diagram: a package, a rectangle, a node and the like, or a `together` block. */
final class DescriptionGroup
{
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $color = null;
    /** Whether its stereotypes are written, as `hide stereotype` leaves them. */
    public bool $stereotypeShown = true;

    public function __construct(
        public readonly string $id,
        public readonly string $display,
        public readonly string $symbol = 'package',
        public readonly ?string $parent = null,
    ) {}
}
