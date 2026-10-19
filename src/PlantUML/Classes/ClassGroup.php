<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

/** A package, namespace or `together` block, inside its parent or at the top. */
final class ClassGroup
{
    public ?string $stereotype = null;
    public ?string $color = null;

    public function __construct(
        public readonly string $id,
        public readonly string $display,
        public readonly string $type = 'package',
        public readonly ?string $parent = null,
    ) {}
}
