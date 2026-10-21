<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

/** What a value of a JSON or YAML diagram is, as PlantUML's `JsonValue` tells them apart. */
enum JsonKind
{
    case Object;
    case Array;
    case String;
    case Number;
    case True;
    case False;
    case Null;
}
