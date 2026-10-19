<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

use Janalis\Viz\Model\LineStyle;

/** A connection from one node to another, by their identifiers. */
final readonly class Edge
{
    public function __construct(
        public string $from,
        public string $to,
        public string $label = '',
        public ArrowHead $head = ArrowHead::Arrow,
        public ArrowHead $tail = ArrowHead::None,
        public LineStyle $style = LineStyle::Solid,
    ) {}
}
