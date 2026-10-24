<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A PlantUML note in points: its text in a box whose top right corner is folded down — or, as
 * svek's opale draws a note linked to one entity, with a point out of the side its link leaves
 * by, reaching where the link ends.
 */
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

        return [$width + 11 + self::FOLD, $height + 10];
    }

    /**
     * The note with its top left at a point, its outline pointing from where its link leaves it
     * to where the link ends: out of the side nearest the first, 8 wide.
     *
     * @param array{float, float} $start
     * @param array{float, float} $tip
     * @return list<Mark>
     */
    public function pointing(float $x, float $y, array $start, array $tip): array
    {
        [$width, $height] = $this->size();
        [$sx, $sy] = [$start[0] - $x, $start[1] - $y];
        $tip = [$tip[0] - $x, $tip[1] - $y];
        $sides = ['left' => \abs($sx), 'right' => \abs($sx - $width), 'up' => \abs($sy), 'down' => \abs($sy - $height)];
        $side = (string) \array_search(\min($sides), $sides, strict: true);
        $fold = [[$width, self::FOLD], [$width - self::FOLD, 0.0]];
        $outline = match ($side) {
            'left' => [[0.0, 0.0], [0.0, $y1 = self::clamp($sy - 4, 0.0, $height - 8)], $tip, [0.0, $y1 + 8], [0.0, $height], [$width, $height], ...$fold],
            'right' => [[0.0, 0.0], [0.0, $height], [$width, $height], [$width, ($y1 = self::clamp($sy - 4, self::FOLD, $height - 8)) + 8], $tip, [$width, $y1], ...$fold],
            'up' => [[0.0, 0.0], [0.0, $height], [$width, $height], ...$fold, [($x1 = self::clamp($sx - 4, 0.0, $width - self::FOLD)) + 8, 0.0], $tip, [$x1, 0.0]],
            default => [[0.0, 0.0], [0.0, $height], [$x1 = self::clamp($sx - 4, 0.0, $width), $height], $tip, [$x1 + 8, $height], [$width, $height], ...$fold],
        };

        return $this->drawn($x, $y, \array_map(static fn (array $point): array => [$x + $point[0], $y + $point[1]], $outline));
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return \max($min, \min($max, $value));
    }

    /** @return list<Mark> the note with its top left at a point */
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $right = $x + $width;

        return $this->drawn($x, $y, [[$x, $y], [$right - self::FOLD, $y], [$right, $y + self::FOLD], [$right, $y + $height], [$x, $y + $height]]);
    }

    /**
     * @param list<array{float, float}> $outline
     * @return list<Mark>
     */
    private function drawn(float $x, float $y, array $outline): array
    {
        [$width] = $this->size();
        $line = $this->style->color('LineColor', '#181818');
        $stroke = $this->style->number('LineThickness', 0.5);
        $right = $x + $width;

        return [
            new Mark(
                MarkKind::Polygon,
                $outline,
                stroke: $line,
                fill: $this->back ?? $this->style->color('BackGroundColor', '#feffdd'),
                strokeWidth: $stroke,
                // As PlantUML, which draws it as a path.
                outline: Outline::Path,
            ),
            new Mark(MarkKind::Polyline, [[$right - self::FOLD, $y], [$right - self::FOLD, $y + self::FOLD], [$right, $y + self::FOLD]], stroke: $line, strokeWidth: $stroke),
            ...$this->text->marks($x + 6, $y + 5, $width - 12 - self::FOLD, 'left'),
        ];
    }
}
