<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/** How a term is boxed, as PlantUML's `ETileBox` tells them apart by their `Symbol`. */
enum EbnfSymbol
{
    /** A `"string"` or `'string'`, or a regex's letters: a plain box. */
    case Terminal;
    /** A `? special sequence ?`: a dashed box. */
    case Special;
    /** A rule's name, or a regex's class or anchor: a rounded, filled box. */
    case Literal;
}
