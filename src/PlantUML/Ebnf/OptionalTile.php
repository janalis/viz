<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/**
 * An optional term as PlantUML's `ETileOptional2` draws it: the line running straight over
 * it, 10 down from its top, and a swerve down to the term, 24 in from either side, and back
 * up; a comment above or below it in a note, 10 away.
 */
final class OptionalTile extends EbnfTile
{
    private const float DELTA_X = 24.0;
    private const float DELTA_Y = 20.0;
    private const float CORNER = 12.0;

    private ?string $above = null;
    private ?string $below = null;

    public function __construct(
        private readonly EbnfTile $orig,
        private readonly EbnfStyle $style,
    ) {}

    #[\Override]
    public function width(): float
    {
        return $this->orig->width() + (2 * self::DELTA_X);
    }

    #[\Override]
    public function h1(): float
    {
        return 10 + $this->noteRoom($this->above);
    }

    #[\Override]
    public function h2(): float
    {
        return 10 + $this->orig->height() + $this->noteRoom($this->below);
    }

    #[\Override]
    public function commentAbove(string $comment): void
    {
        $this->above = $comment;
    }

    #[\Override]
    public function commentBelow(string $comment): void
    {
        $this->below = $comment;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        $width = $this->width();
        $line = $y + $this->h1();
        $deltaY = self::DELTA_Y + $this->noteRoom($this->above);
        $drop = $deltaY + $this->orig->h1() - $this->h1();
        $marks = [
            ...$pen->directed($line, $x, $x + $width, 0.4, 25.0),
            ...$pen->zigzagDown($x, $line, 9.0, 2 * self::CORNER, $drop),
            ...$pen->zigzagUp($x + $width - (2 * self::CORNER), $line, 9.0, 2 * self::CORNER, $drop),
            ...$this->orig->marks($x + self::DELTA_X, $y + $deltaY, $pen),
        ];
        if (null !== $this->above) {
            $note = $this->style->note($this->above);
            \array_push($marks, ...$note->marks($x + (($width - $note->size()[0]) / 2), $y));
        }
        if (null !== $this->below) {
            $note = $this->style->note($this->below);
            [$noteWidth, $noteHeight] = $note->size();
            \array_push($marks, ...$note->marks($x + (($width - $noteWidth) / 2), $y + $this->height() - $noteHeight));
        }

        return $marks;
    }

    /** The room a note takes: its height and 10 to the term. */
    private function noteRoom(?string $comment): float
    {
        return null === $comment ? 0.0 : $this->style->note($comment)->size()[1] + 10;
    }
}
