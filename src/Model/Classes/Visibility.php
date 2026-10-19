<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** A member's visibility, as both languages mark it before its name. */
enum Visibility: string
{
    case Public = '+';
    case Private = '-';
    case Protected = '#';
    case Package = '~';
    case Unspecified = '';
}
