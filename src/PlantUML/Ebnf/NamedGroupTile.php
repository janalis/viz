<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\Classes\ClassBox;

/**
 * A regex's named group as PlantUML's `ETileNamedGroup` draws it: the term 10 in all round
 * on a pale blue back, the name on a tab of the same blue above it.
 */
final class NamedGroupTile extends EbnfTile
{
    private const float DELTA = 10.0;
    private const string BACK = '#e8e8ff';

    public function __construct(
        private readonly EbnfTile $orig,
        private readonly EbnfStyle $style,
        private readonly string $name,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + (2 * self::DELTA);
    }

    #[\Override]
    public function h1(): float
    {
        return self::DELTA + $this->orig->h1();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->orig->h2() + self::DELTA;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        [$textWidth, $textHeight] = $this->style->measure($this->name);
        $width = $this->width();
        $line = $y + $this->h1();

        return [
            ClassBox::rectangle($x, $y, $width, $this->height(), self::BACK, self::BACK, 1.0, 0.0),
            ClassBox::rectangle($x, $y - $textHeight, $textWidth + 10, $textHeight, self::BACK, self::BACK, 1.0, 0.0),
            ...$pen->line($x, $line, $x + self::DELTA, $line),
            ...$pen->line($x + $width - self::DELTA, $line, $x + $width, $line),
            ...$this->orig->marks($x + self::DELTA, $y + self::DELTA, $pen),
            // Its baseline on the group's top, as PlantUML sets it.
            ...$this->style->word($this->name, $x + 5, $y + $this->style->descent() - $textHeight),
        ];
    }
}
