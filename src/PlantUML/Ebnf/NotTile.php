<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Text\FontFace;

/** An exception, `- x`, as PlantUML's `ETileNot` draws it: `NOT` 3 in, then the term 8 past it. */
final class NotTile extends EbnfTile
{
    private const string TEXT = 'NOT';

    public function __construct(
        private readonly EbnfTile $orig,
        private readonly EbnfStyle $style,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + $this->style->measure(self::TEXT)[0] + 8;
    }

    #[\Override]
    public function h1(): float
    {
        return $this->orig->h1();
    }

    #[\Override]
    public function h2(): float
    {
        return $this->orig->h2();
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        [$width, $height] = $this->style->measure(self::TEXT);
        // Its baseline its descent and 1 under the line, as PlantUML sets it.
        $top = $y + $this->h1() + (2 * $this->style->descent()) + 1 - $height;

        return [...$this->style->word(self::TEXT, $x + 3, $top, face: FontFace::Regular), ...$this->orig->marks($x + $width + 8, $y, $pen)];
    }
}
