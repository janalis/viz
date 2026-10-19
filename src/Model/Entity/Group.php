<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** A frame around entities by id, and nested frames. */
final readonly class Group
{
    /**
     * @param list<string> $entities
     * @param list<Group>  $groups
     */
    public function __construct(
        public string $id,
        public string $label = '',
        public array $entities = [],
        public array $groups = [],
    ) {}
}
