<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Json\JsonNode;

/**
 * A state as a PlantUML state diagram declares it — or a pseudostate: `start` and `end` for
 * a scope's `[*]`, `choice`, `fork`, `join`, `history` and `deephistory` — in the composite
 * state and the region that hold it.
 */
final class StateNode
{
    /** @var list<string> the lines written after `State :` */
    public array $description = [];
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $color = null;
    /** Laid out but not drawn, as `hide` hides it. */
    public bool $hidden = false;
    /** Taken out with its transitions and notes, as `remove` takes it out. */
    public bool $removed = false;
    /** A `json` entity's data, which it is drawn as. */
    public ?JsonNode $json = null;

    /** @param list<string> $display */
    public function __construct(
        public readonly string $id,
        public array $display,
        public string $kind = 'state',
        public readonly ?string $group = null,
        public readonly int $region = 0,
    ) {}

    /** Adds the lines of a `State : text`, broken at each `\n`, as PlantUML breaks them. */
    public function describe(string $text): void
    {
        \array_push($this->description, ...ClassReader::split($text));
    }
}
