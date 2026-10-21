<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

enum JsonKind
{
    case String;
    case Number;
    /** `true`, `false` or `null` */
    case Literal;
    case Array;
    case Object;
}
