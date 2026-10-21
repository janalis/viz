<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/**
 * A regex's character class, `[a-z0-9]`, as PlantUML's `ETileRegexGroup` draws it: its
 * ranges and characters one under the other, thin lines between them, in a dashed box 5
 * wider either side; or, `[^abc]`, as its `ETileRegexGroupAllBut` draws it, the `^` in a
 * bracket on the left of the others' box.
 */
final class RegexGroupTile extends EbnfTile
{
    /** @param list<string> $elements */
    public function __construct(
        private readonly array $elements,
        private readonly EbnfStyle $style,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->allBut() ? $this->size([$this->elements[0]])[0] + $this->size($this->rest())[0] + 20 : $this->size($this->elements)[0] + 10;
    }

    #[\Override]
    public function h1(): float
    {
        return $this->half();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->half();
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        $line = $this->style->line;
        if (!$this->allBut()) {
            [$textWidth, $textHeight] = $this->size($this->elements);
            $left = $x + (($this->width() - $textWidth - 10) / 2);

            return [self::dashed(ClassBox::rectangle($left, $y, $textWidth + 10, $textHeight, $line, 'none', 1.0, 0.0)->points, $line), ...$this->list($this->elements, $left, $y, $textWidth + 10)];
        }
        [$firstWidth, $firstHeight] = $this->size([$this->elements[0]]);
        [$restWidth, $restHeight] = $this->size($this->rest());
        $firstTop = $y + (($this->height() - $firstHeight) / 2);
        $bracket = $x + $firstWidth + 10;

        return [
            self::dashed(ClassBox::rectangle($bracket, $y, $restWidth + 10, $restHeight, $line, 'none', 1.0, 0.0)->points, $line),
            new Mark(
                MarkKind::Polyline,
                [[$bracket, $firstTop], [$x, $firstTop], [$x, $firstTop + $firstHeight], [$bracket, $firstTop + $firstHeight]],
                stroke: $line,
                dashed: true,
                strokeWidth: 1.0,
                dashes: [5.0, 5.0],
            ),
            ...$this->list([$this->elements[0]], $x, $firstTop, $firstWidth + 10),
            ...$this->list($this->rest(), $bracket, $y, $firstWidth + 10),
        ];
    }

    private function allBut(): bool
    {
        return '^' === $this->elements[0];
    }

    /** @return list<string> */
    private function rest(): array
    {
        return \array_slice($this->elements, offset: 1);
    }

    private function half(): float
    {
        return $this->allBut() ? \max($this->size([$this->elements[0]])[1], $this->size($this->rest())[1]) / 2 : $this->size($this->elements)[1] / 2;
    }

    /**
     * @param list<string> $elements
     * @return array{float, float} their widest and their heights together
     */
    private function size(array $elements): array
    {
        $width = 0.0;
        $height = 0.0;
        foreach ($elements as $element) {
            [$elementWidth, $elementHeight] = $this->style->measure($element);
            $width = \max($width, $elementWidth);
            $height += $elementHeight;
        }

        return [$width, $height];
    }

    /**
     * @param list<string> $elements
     * @return list<Mark> each element 5 in, a line 0.3 thick over all but the first, as wide as a width
     */
    private function list(array $elements, float $x, float $y, float $width): array
    {
        $marks = [];
        $top = $y;
        foreach ($elements as $element) {
            if ($top > $y) {
                $marks[] = new Mark(MarkKind::Polyline, [[$x, $top], [$x + $width, $top]], stroke: $this->style->line, strokeWidth: 0.3);
            }
            \array_push($marks, ...$this->style->word($element, $x + 5, $top));
            $top += $this->style->measure($element)[1];
        }

        return $marks;
    }

    /** @param list<array{float, float}> $points */
    private static function dashed(array $points, string $line): Mark
    {
        return new Mark(MarkKind::Polygon, $points, stroke: $line, fill: 'none', dashed: true, strokeWidth: 1.0, dashes: [5.0, 5.0]);
    }
}
