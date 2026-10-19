<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

/** What a link between two elements means. */
enum AssociationKind: string
{
    case Association = 'association';
    /** The first use case always includes the second. */
    case Include = 'include';
    /** The first use case may extend the second. */
    case Extend = 'extend';
    /** The first element is a kind of the second. */
    case Generalization = 'generalization';
}
