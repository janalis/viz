<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

/** What a line of YAML holds, as PlantUML's `YamlLineType` tells them apart. */
enum YamlLineType
{
    case Empty;
    /** Text with no key, as a folded value runs on. */
    case Text;
    case KeyOnly;
    case KeyAndValue;
    /** A key and `[…]`. */
    case FlowSequence;
    /** A key and `|`, its value on the lines after. */
    case BlockStyle;
    /** A key and `>`, which PlantUML fails on. */
    case FoldedStyle;
    /** `- value`. */
    case ListElement;
    /** `-` alone. */
    case PlainDash;
}
