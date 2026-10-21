<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/** Text in a timing diagram's style, each line as tall as PlantUML measures its font, set left from a top left. */
final readonly class TimingText
{
    /** PlantUML's sans-serif line, against its size. */
    private const float LINE = 1.177_734_375;

    /** @param list<string> $lines */
    public function __construct(
        private array $lines,
        private Style $style,
        private ?string $color = null,
    ) {}

    /** @return array{float, float} none for no text */
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->lines as $line) {
            [$lineWidth, $lineHeight] = $this->text($line)->size();
            [$width, $height] = [\max($width, $lineWidth), $height + \max($this->line(), $lineHeight)];
        }

        return [$width, $height];
    }

    /** @return list<Mark> */
    public function marks(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->lines as $line) {
            $text = $this->text($line);
            [$width, $height] = $text->size();
            \array_push($marks, ...$text->marks($x, $y, $width, 'left'));
            $y += \max($this->line(), $height);
        }

        return $marks;
    }

    private function line(): float
    {
        return $this->style->fontSize() * self::LINE;
    }

    private function text(string $line): PlantUmlText
    {
        $text = PlantUmlText::styled([$line], $this->style);

        return null === $this->color ? $text : new PlantUmlText($text->lines, $text->size, $text->face, $this->color);
    }
}
