<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

use Janalis\Viz\Model\LineStyle;

/** A relation between two classifiers, its two ends marked separately, with a label at each end and in the middle. */
final readonly class Relation
{
    public function __construct(
        public string $from,
        public string $to,
        public RelationEnd $fromEnd = RelationEnd::None,
        public RelationEnd $toEnd = RelationEnd::Arrow,
        public LineStyle $line = LineStyle::Solid,
        public string $label = '',
        public string $fromLabel = '',
        public string $toLabel = '',
    ) {}
}
