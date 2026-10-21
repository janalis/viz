<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;

/** Texts one above the other, as PlantUML stacks a title under its stereotypes: as wide as the widest. */
final readonly class StackedText
{
    /** @param list<PlantUmlText> $texts from the top */
    public function __construct(
        private array $texts,
    ) {}

    /** @return array{float, float} */
    public function size(): array
    {
        $width = 0.0;
        $height = 0.0;
        foreach ($this->texts as $text) {
            [$textWidth, $textHeight] = $text->size();
            $width = \max($width, $textWidth);
            $height += $textHeight;
        }

        return [$width, $height];
    }

    /** @return list<Mark> each text's, set left, centred or right within a width, from a top left */
    public function marks(float $x, float $y, float $width, string $align = 'center'): array
    {
        $marks = [];
        foreach ($this->texts as $text) {
            \array_push($marks, ...$text->marks($x, $y, $width, $align));
            $y += $text->size()[1];
        }

        return $marks;
    }
}
