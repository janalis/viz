<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A line of Creole drawn as a rule across its text, as PlantUML's `CreoleHorizontalLine` draws
 * one: `--title--` a line, `==title==` or `===…==` a doubled one, `..title..` a dotted one; 10
 * by 10 with no title, as big as its title with one, the title in its middle.
 */
final readonly class PlantUmlRule
{
    private function __construct(
        private string $style,
        private ?PlantUmlText $title,
    ) {}

    /** The rule a line of a text is, as PlantUML's Creole finds one; null for any other line. */
    public static function of(string $line, PlantUmlText $text): ?self
    {
        $match = [];
        $style = match (true) {
            1 === \preg_match('/^--([^-]*)--$/', $line, $match) => '-',
            1 === \preg_match('/^==([^=]*)==$/', $line, $match), 1 === \preg_match('/^===*==$/', $line) => '=',
            1 === \preg_match('/^\.\.([^.]*)\.\.$/', $line, $match) => '.',
            default => null,
        };
        if (null === $style) {
            return null;
        }
        $title = $match[1] ?? '';

        return new self($style, '' === $title ? null : new PlantUmlText([$title], $text->size, $text->face, $text->color, $text->sprites, $text->background));
    }

    /** @return array{float, float} */
    public function size(): array
    {
        return null === $this->title ? [10.0, 10.0] : $this->title->size();
    }

    /**
     * The rule across a width from a point, at half its height: doubled 2 below, or dotted; its
     * title in its middle, half a point above its top.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, float $width, string $color): array
    {
        [$titleWidth, $height] = null === $this->title ? [0.0, 10.0] : $this->title->size();
        $middle = $y + ($height / 2);
        $gap = ($width - $titleWidth) / 2;
        $spans = null === $this->title ? [[$x, $x + $width]] : [[$x, $x + $gap], [$x + $width - $gap, $x + $width]];
        $marks = [];
        foreach ([$middle, ...('=' === $this->style ? [$middle + 2] : [])] as $at) {
            foreach ($spans as [$left, $right]) {
                $marks[] = new Mark(
                    MarkKind::Polyline,
                    [[$left, $at], [$right, $at]],
                    stroke: $color,
                    dashed: '.' === $this->style,
                    strokeWidth: 1.0,
                    dashes: '.' === $this->style ? [1.0, 2.0] : null,
                );
            }
        }
        if (null !== $this->title) {
            \array_push($marks, ...$this->title->marks($x + $gap, $y - 0.5, $titleWidth, 'left'));
        }

        return $marks;
    }
}
