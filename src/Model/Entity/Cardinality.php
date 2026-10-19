<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** How many entities one end of a relationship holds, as crow's-foot notation draws it. */
enum Cardinality: string
{
    case ZeroOrOne = '0..1';
    case ExactlyOne = '1';
    case ZeroOrMore = '0..*';
    case OneOrMore = '1..*';
}
