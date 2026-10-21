<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\Draw\PlantUmlText;

/** The height of a term's box in the bundled font: a line of 14 points and 5 above and below. */
final class PlantUmlTestFont
{
    public static function line(): float
    {
        return PlantUmlText::lineHeight(14.0) + 10;
    }
}
