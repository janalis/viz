<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

enum TimTokenType
{
    /** The sign PlantUML writes a subtraction's `-` as, apart from a negative number's. */
    public const string MINUS = "\u{2052}";

    case QuotedString;
    case JsonData;
    case Operator;
    case OpenParenMath;
    case Comma;
    case CloseParenMath;
    case Number;
    case PlainText;
    case Spaces;
    case FunctionName;
    case OpenParenFunc;
    case CloseParenFunc;
    case Affectation;
}
