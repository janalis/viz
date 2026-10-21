<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/**
 * A repeated term as PlantUML's `ETileOneOrMore` draws it: the term 15 in from either side,
 * a loop over it, 8 round, back from its end to its start; with a count, as `3 * x` or `{3}`
 * writes one, a brace over the loop and the count, 2 points smaller, centred above it.
 */
final class OneOrMoreTile extends EbnfTile
{
    private const float DELTA_X = 15.0;
    private const float DELTA_Y = 12.0;
    private const float CORNER = 8.0;

    public function __construct(
        private readonly EbnfTile $orig,
        private readonly EbnfStyle $style,
        private readonly ?string $loop = null,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + (2 * self::DELTA_X);
    }

    #[\Override]
    public function h1(): float
    {
        return self::DELTA_Y + $this->orig->h1() + $this->brace();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->orig->h2();
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        $width = $this->width();
        $h1 = $y + $this->h1();
        $loop = $y + 5 + $this->brace();
        [$left, $right] = [$x + self::CORNER, $x + $width - self::CORNER];
        $marks = [
            ...$pen->corner('sw', self::CORNER, $left, $h1),
            ...$pen->line($left, $loop + self::CORNER, $left, $h1 - self::CORNER),
            ...$pen->corner('nw', self::CORNER, $left, $loop),
            ...$pen->antiDirected($loop, $x + self::DELTA_X, $x + $width - self::DELTA_X, 0.6),
            ...$pen->corner('se', self::CORNER, $right, $h1),
            ...$pen->line($right, $loop + self::CORNER, $right, $h1 - self::CORNER),
            ...$pen->corner('ne', self::CORNER, $right, $loop),
            ...$pen->line($x, $h1, $x + self::DELTA_X, $h1),
            ...$pen->line($x + $width - self::DELTA_X, $h1, $x + $width, $h1),
            ...$this->orig->marks($x + self::DELTA_X, $y + self::DELTA_Y + $this->brace(), $pen),
        ];
        if (null === $this->loop) {
            return $marks;
        }
        // PlantUML's `Brace`, 10 down, its halves meeting under the count.
        $brace = $pen->with(0.5);
        [$half, $top] = [$width / 2, $y + 10];
        \array_push(
            $marks,
            ...$brace->corner('nw', 5.0, $x, $top),
            ...$brace->corner('se', 5.0, $x + $half, $top),
            ...$brace->corner('sw', 5.0, $x + $half, $top),
            ...$brace->corner('ne', 5.0, $x + $width, $top),
            ...$brace->line($x + 5, $top, $x + $half - 5, $top),
            ...$brace->line($x + 5 + $half, $top, $x + $width - 5, $top),
        );
        $size = $this->style->size - 2;
        [$textWidth, $textHeight] = $this->style->measure($this->loop, $size);
        // Its baseline its descent down, as PlantUML sets it.
        $descent = $this->style->descent($size);

        return [...$marks, ...$this->style->word($this->loop, $x + (($width - $textWidth) / 2), $y + (2 * $descent) - $textHeight, $size)];
    }

    private function brace(): float
    {
        return null === $this->loop ? 0.0 : 15.0;
    }
}
