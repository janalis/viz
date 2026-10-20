<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/** Text in a style's font, its lines set left, centred or right within its widest. */
final readonly class Label implements TextBlock
{
    private PlantUmlText $text;

    /** @param list<string> $lines */
    public function __construct(
        array $lines,
        Style $style,
        private string $align = 'left',
    ) {
        $this->text = PlantUmlText::styled($lines, $style);
    }

    #[\Override]
    public function size(): array
    {
        return $this->text->size();
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        [$width, $height] = $this->text->size();
        if (0.0 === $width) {
            return [];
        }

        return [new Shape(ShapeKind::Text, $this->text->marks($x, $y, $width, $this->align), $x, $y, $x + $width, $y + $height)];
    }
}
