<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A component's text as PlantUML's textual components hold it: its lines in its style's font,
 * each set left, centred or right as the style aligns them, a message's number before them
 * 4 apart, and the padding the component measures around them. A single empty line is no
 * text at all.
 */
final readonly class RoseText
{
    /** @param array{float, float, float, float} $padding top, right, bottom and left */
    private function __construct(
        private PlantUmlText $text,
        private string $align,
        public array $padding,
        private ?PlantUmlText $number,
    ) {}

    /**
     * @param list<string> $lines
     * @param array{float, float, float, float} $padding top, right, bottom and left
     */
    public static function of(array $lines, Style $style, array $padding, ?string $number = null): self
    {
        $text = PlantUmlText::styled($lines, $style);

        return new self(
            $text,
            \strtolower($style->value('HorizontalAlignment', 'left')),
            $padding,
            null === $number ? null : new PlantUmlText([$number], $text->size, $text->face, $text->color),
        );
    }

    /** @return array{float, float} the text's own size, without its padding */
    public function pure(): array
    {
        [$width, $height] = $this->text->size();
        if (null === $this->number) {
            return [$width, $height];
        }
        [$numberWidth, $numberHeight] = $this->number->size();

        return [$numberWidth + 4 + $width, \max($numberHeight, $height)];
    }

    /** The text's width with its padding left and right. */
    public function width(): float
    {
        return $this->pure()[0] + $this->padding[1] + $this->padding[3];
    }

    /** The text's height with its padding above and below. */
    public function height(): float
    {
        return $this->pure()[1] + $this->padding[0] + $this->padding[2];
    }

    /** @return list<Mark> the text with its block's top left at a point, its padding not included */
    public function marks(float $x, float $y): array
    {
        if (null === $this->number) {
            // As PlantUML writes a line: without the spaces it ends with, which it measures.
            $written = new PlantUmlText(\array_map(\rtrim(...), $this->text->lines), $this->text->size, $this->text->face, $this->text->color, $this->text->sprites, $this->text->background);

            return $written->marks($x, $y, $this->text->size()[0], $this->align);
        }
        [$numberWidth, $numberHeight] = $this->number->size();
        [$width, $height] = $this->text->size();
        $tallest = \max($numberHeight, $height);

        return [
            ...$this->number->marks($x, $y + (($tallest - $numberHeight) / 2), $numberWidth, 'left'),
            ...$this->text->marks($x + $numberWidth + 4, $y + (($tallest - $height) / 2), $width, $this->align),
        ];
    }
}
