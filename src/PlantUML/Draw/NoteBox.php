<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/** A PlantUML note in points: its text in a box whose top right corner is folded down. */
final readonly class NoteBox
{
    private const float FOLD = 10.0;

    public function __construct(
        private PlantUmlText $text,
        private Style $style,
        private ?string $back = null,
    ) {}

    /** @return array{float, float} */
    public function size(): array
    {
        [$width, $height] = $this->text->size();

        return [$width + 12 + self::FOLD, $height + 10];
    }

    /** @return list<Mark> the note with its top left at a point */
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $line = $this->style->color('LineColor', '#181818');
        $stroke = $this->style->number('LineThickness', 0.5);
        $right = $x + $width;

        return [
            new Mark(
                MarkKind::Polygon,
                [[$x, $y], [$right - self::FOLD, $y], [$right, $y + self::FOLD], [$right, $y + $height], [$x, $y + $height]],
                stroke: $line,
                fill: $this->back ?? $this->style->color('BackGroundColor', '#feffdd'),
                strokeWidth: $stroke,
            ),
            new Mark(MarkKind::Polyline, [[$right - self::FOLD, $y], [$right - self::FOLD, $y + self::FOLD], [$right, $y + self::FOLD]], stroke: $line, strokeWidth: $stroke),
            ...$this->text->marks($x + 6, $y + 5, $width - 12 - self::FOLD, 'left'),
        ];
    }
}
