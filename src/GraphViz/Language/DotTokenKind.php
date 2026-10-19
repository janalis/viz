<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Language;

/** The kinds of token the DOT language has. */
enum DotTokenKind: string
{
    /** An identifier, a numeral or a quoted string: any of them names a thing or gives a value. */
    case Id = 'id';
    /** An HTML string, `<…>` with its angle brackets balanced. */
    case Html = 'html';
    case Keyword = 'keyword';
    case EdgeOp = 'edgeop';
    case OpenBrace = '{';
    case CloseBrace = '}';
    case OpenBracket = '[';
    case CloseBracket = ']';
    case Equals = '=';
    case Semicolon = ';';
    case Comma = ',';
    case Colon = ':';
    case End = 'end';
}
