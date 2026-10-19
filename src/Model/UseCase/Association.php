<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

use Janalis\Viz\Model\Graph\ArrowHead;

/** A link between two elements, with the marks of a plain association at each end. */
final readonly class Association
{
    public function __construct(
        public string $from,
        public string $to,
        public AssociationKind $kind = AssociationKind::Association,
        public string $label = '',
        /** The mark at $to; an include, extend or generalization draws its own. */
        public ArrowHead $head = ArrowHead::Arrow,
        /** The mark at $from. */
        public ArrowHead $tail = ArrowHead::None,
    ) {}
}
