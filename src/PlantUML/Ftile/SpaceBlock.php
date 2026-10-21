<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/** A title left empty, which PlantUML writes as a space: a space's width and a line's height in a style's font, nothing drawn. */
final readonly class SpaceBlock implements TextBlock
{
    public function __construct(
        private Style $style,
    ) {}

    #[\Override]
    public function size(): array
    {
        $size = $this->style->fontSize();

        return [Units::cell($size), PlantUmlText::lineHeight($size)];
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        return [];
    }
}
