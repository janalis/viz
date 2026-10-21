<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/** A group of servers, as PlantUML's `NwGroup` keeps one: their names, its colour and description, and the box their columns stand in. */
final class NwGroup
{
    /** @var array<string, true> */
    public array $names = [];
    public ?string $color = null;
    public ?string $description = null;
    public NwBox $box;

    public function __construct(
        public readonly ?string $name,
    ) {
        $this->box = new NwBox();
    }

    public function contains(string $server): bool
    {
        return \array_key_exists($server, $this->names);
    }
}
