<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** A relationship between two entities, each end with its cardinality. An identifying one draws a solid line. */
final readonly class Relationship
{
    public function __construct(
        public string $from,
        public string $to,
        public Cardinality $fromCardinality = Cardinality::ExactlyOne,
        public Cardinality $toCardinality = Cardinality::ZeroOrMore,
        public bool $identifying = true,
        public string $label = '',
    ) {}
}
