<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/**
 * A regex's lookahead or lookbehind as PlantUML's `ETileLookAheadOrBehind` draws it: its
 * kind — `?=`, `?!`, `?<=`, `?<!` — then the term, 6 more above and below, in a dotted,
 * rounded box.
 */
final class LookAroundTile extends EbnfTile
{
    private const float DELTA_X1 = 4.0;
    private const float DELTA_X2 = 8.0;
    private const float DELTA_Y = 6.0;

    public function __construct(
        private readonly EbnfTile $orig,
        private readonly EbnfStyle $style,
        private readonly string $kind,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + self::DELTA_X1 + self::DELTA_X2 + $this->style->measure($this->kind)[0];
    }

    #[\Override]
    public function h1(): float
    {
        return self::DELTA_Y + $this->orig->h1();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->orig->h2() + self::DELTA_Y;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        [$textWidth, $textHeight] = $this->style->measure($this->kind);
        $box = ClassBox::rectangle($x, $y, $this->width(), $this->height(), $this->style->line, 'none', 1.0, 30.0);

        return [
            new Mark(MarkKind::Polygon, $box->points, stroke: $this->style->line, fill: 'none', dashed: true, strokeWidth: 1.0, dashes: [2.0, 3.0]),
            // Its baseline 2 and its descent under the line, as PlantUML sets it.
            ...$this->style->word($this->kind, $x + 4, $y + 2 + $this->h1() + (2 * $this->style->descent()) - $textHeight),
            ...$this->orig->marks($x + self::DELTA_X1 + $textWidth, $y + self::DELTA_Y, $pen),
        ];
    }
}
