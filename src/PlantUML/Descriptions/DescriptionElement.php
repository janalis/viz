<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

/**
 * An element of a component, deployment or use case diagram as declared: its symbol —
 * `actor`, `usecase`, `component`, `node`, `database`, `interface` and the rest, or `unknown`
 * until the diagram decides, for a name only a link has used — in the group that holds it.
 */
final class DescriptionElement
{
    /** @var list<string> */
    public array $stereotypes = [];
    public ?string $color = null;
    /** Whether its stereotypes are written, as `hide stereotype` leaves them. */
    public bool $stereotypeShown = true;

    /** @param list<string> $display its text, a line each */
    public function __construct(
        public readonly string $id,
        public array $display,
        public string $symbol = 'unknown',
        public readonly ?string $group = null,
        /** A business actor or use case, written with a slash. */
        public bool $business = false,
    ) {}
}
