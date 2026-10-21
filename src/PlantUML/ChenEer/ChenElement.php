<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

/**
 * An entity, a relationship, an attribute or a subclass circle of a Chen EER diagram: its id —
 * an attribute's under its owner's, `owner/name` — its text, a line each, its stereotypes,
 * and the colour written after it: an entity's or a relationship's back, an attribute's line.
 */
final readonly class ChenElement
{
    /**
     * @param list<string> $display
     * @param list<string> $stereotypes
     */
    public function __construct(
        public string $id,
        public ChenKind $kind,
        public array $display,
        public array $stereotypes = [],
        public ?string $back = null,
        public ?string $line = null,
    ) {}

    public function has(string $stereotype): bool
    {
        return \in_array($stereotype, $this->stereotypes, strict: true);
    }
}
