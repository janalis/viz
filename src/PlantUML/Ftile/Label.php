<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * Text in a style's font, its lines set left, centred or right within its widest. PlantUML's
 * limit finder, as measured on its output, takes a text from 1.5 below its first line's top
 * less its descent, above its block in a big font: a label measured as drawn, as some arrows'
 * are, takes that room and its descent, to a reach below its baseline, its text that far down
 * in it; a lowered one stands that far down in its block alone.
 */
final readonly class Label implements TextBlock
{
    private PlantUmlText $text;

    /**
     * @param list<string> $lines
     * @param ?float $reach how far below its baseline it is taken to reach when measured as drawn; null when it is measured as its block
     * @param bool $lowered whether its text stands as low in its block as it is taken to reach above it
     */
    public function __construct(
        array $lines,
        Style $style,
        private string $align = 'left',
        private ?float $reach = null,
        private bool $lowered = false,
    ) {
        $this->text = PlantUmlText::styled($lines, $style);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->text->size();

        return [$width, null === $this->reach ? $height : $height + $this->rise() - \max(0.0, $this->descent() - $this->reach)];
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        [$width, $height] = $this->text->size();
        if (0.0 === $width) {
            return [];
        }
        $marks = $this->text->marks($x, $y + (null !== $this->reach || $this->lowered ? $this->rise() : 0.0), $width, $this->align);
        if (null !== $this->reach) {
            return [new Shape(ShapeKind::Text, $marks, $x, $y, $x + $width, $y + $this->size()[1])];
        }

        // As PlantUML's compression takes text: from its first line's height less 1.5 above its baseline to 1.5 below its last.
        [$above, $short] = $this->text->overhang();
        $lowered = $this->lowered ? $this->rise() : 0.0;

        return [new Shape(ShapeKind::Text, $marks, $x, $y, $x + $width, $y + $height, rise: $this->lowered ? 0.0 : $this->rise(), overhang: [$above - $lowered, $short - $lowered])];
    }

    /** How far above its block the limit finder takes the text to reach. */
    private function rise(): float
    {
        return \max(0.0, $this->descent() - 1.5);
    }

    private function descent(): float
    {
        return 0.210_937_5 * $this->text->size;
    }
}
