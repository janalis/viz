<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * Text in a style's font, its lines set left, centred or right within its widest. PlantUML's
 * limit finder, as measured on its output, takes a text from 1.5 below its first line's top
 * less its descent, above its block in a big font, to at most 4 below its baseline: a label
 * measured as drawn, as an arrow's is, takes that room, its text that far down in it.
 */
final readonly class Label implements TextBlock
{
    /** How far below its baseline PlantUML's limit finder takes a text to reach, at most. */
    private const float FOOT = 4.0;

    private PlantUmlText $text;

    /**
     * @param list<string> $lines
     * @param bool $drawn whether it is measured as drawn, as an arrow's label is
     */
    public function __construct(
        array $lines,
        Style $style,
        private string $align = 'left',
        private bool $drawn = false,
    ) {
        $this->text = PlantUmlText::styled($lines, $style);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->text->size();

        return [$width, $this->drawn ? $height + $this->rise() - $this->short() : $height];
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        [$width, $height] = $this->text->size();
        if (0.0 === $width) {
            return [];
        }
        if ($this->drawn) {
            return [new Shape(ShapeKind::Text, $this->text->marks($x, $y + $this->rise(), $width, $this->align), $x, $y, $x + $width, $y + $this->size()[1])];
        }

        return [new Shape(ShapeKind::Text, $this->text->marks($x, $y, $width, $this->align), $x, $y, $x + $width, $y + $height, rise: $this->rise())];
    }

    /** How far above its block the limit finder takes the text to reach. */
    private function rise(): float
    {
        return \max(0.0, (0.210_937_5 * $this->text->size) - 1.5);
    }

    /** How much of its descent below its last baseline the limit finder leaves out. */
    private function short(): float
    {
        return \max(0.0, (0.210_937_5 * $this->text->size) - self::FOOT);
    }
}
