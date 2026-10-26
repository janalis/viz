<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Descriptions\DescriptionElement;

/** A class, interface, enum or other classifier as a PlantUML class diagram declares it, filled in as its lines are read. */
final class ClassEntity
{
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $generic = null;
    /** @var ?array{string, ?string} the character a stereotype's `(C, colour)` circles, and the circle's colour */
    public ?array $spot = null;
    /** @var list<string> the body's lines as written: members, and separators such as `--` and `..` */
    public array $body = [];
    public ?string $visibility = null;
    /** Its colour spec as written after it, such as `#pink` or `#back:pink;line:red`. */
    public ?string $color = null;
    /** Its path through the packages, as PlantUML qualifies its name for `hide` and `remove`; its id where it has none. */
    public ?string $path = null;
    /** What it is drawn as where it is another kind of element, mixed in: an actor, a use case, a component…, as a description diagram draws one. */
    public ?DescriptionElement $element = null;

    /** @param list<string> $display its name as shown, a line each */
    public function __construct(
        public readonly string $id,
        public array $display,
        public string $type = 'class',
        public readonly ?string $group = null,
    ) {}
}
