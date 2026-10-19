<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** A key an attribute takes part in. */
enum Key: string
{
    case Primary = 'PK';
    case Foreign = 'FK';
    case Unique = 'UK';
}
