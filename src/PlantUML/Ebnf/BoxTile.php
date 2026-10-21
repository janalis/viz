<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\NoteBox;

/**
 * A term as PlantUML's `ETileBox` draws it: its text 5 in from its box — a terminal's plain,
 * a special sequence's dashed, a name's rounded and filled — with a comment above or below
 * it in a note pointing at it, 20 away; the box centred when a note is wider, its line run
 * to its sides.
 */
final class BoxTile extends EbnfTile
{
    private ?string $above = null;
    private ?string $below = null;

    public function __construct(
        public readonly string $value,
        public readonly EbnfSymbol $symbol,
        private readonly EbnfStyle $style,
    ) {}

    /** The box with another's text after its own, as PlantUML runs a regex's letters together. */
    public function mergedWith(self $other): self
    {
        return new self($this->value.$other->value, $this->symbol, $this->style);
    }

    #[\Override]
    public function width(): float
    {
        return \max($this->box()[0], $this->noteSize($this->above)[0], $this->noteSize($this->below)[0]);
    }

    #[\Override]
    public function h1(): float
    {
        return ($this->box()[1] / 2) + $this->noteRoom($this->above);
    }

    #[\Override]
    public function h2(): float
    {
        return ($this->box()[1] / 2) + $this->noteRoom($this->below);
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
    public function repetitionLabel(): string
    {
        return $this->value;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        [$boxWidth, $boxHeight] = $this->box();
        $width = $this->width();
        $top = $y + $this->noteRoom($this->above);
        $left = $x + (($width - $boxWidth) / 2);
        $line = $this->style->line;
        $marks = [match ($this->symbol) {
            EbnfSymbol::Terminal => ClassBox::rectangle($left, $top, $boxWidth, $boxHeight, $line, 'none', 0.5, 0.0),
            EbnfSymbol::Special => new Mark(
                MarkKind::Polygon,
                ClassBox::rectangle($left, $top, $boxWidth, $boxHeight, $line, 'none', 1.0, 0.0)->points,
                stroke: $line,
                fill: 'none',
                dashed: true,
                strokeWidth: 1.0,
                dashes: [5.0, 5.0],
            ),
            EbnfSymbol::Literal => ClassBox::rectangle($left, $top, $boxWidth, $boxHeight, $line, $this->style->back, 1.5, 10.0),
        }];
        \array_push($marks, ...$this->style->word($this->value, $left + 5, $top + 5));
        $centre = $x + ($width / 2);
        if (null !== $this->above) {
            $note = $this->style->note($this->above);
            [$noteWidth, $noteHeight] = $note->size();
            \array_push($marks, ...$note->pointing($x + (($width - $noteWidth) / 2), $y, [$centre, $y + $noteHeight], [$centre, $y + $noteHeight + 20]));
        }
        if (null !== $this->below) {
            $note = $this->style->note($this->below);
            [$noteWidth, $noteHeight] = $note->size();
            $noteTop = $y + $this->height() - $noteHeight;
            \array_push($marks, ...$note->pointing($x + (($width - $noteWidth) / 2), $noteTop, [$centre, $noteTop], [$centre, $noteTop - 20]));
        }
        if ($left > $x) {
            $lineY = $y + $this->h1();
            \array_push($marks, ...$pen->directed($lineY, $x, $left, 0.5, 25.0), ...$pen->directed($lineY, $left + $boxWidth, $x + $width, 0.5, 25.0));
        }

        return $marks;
    }

    /** @return array{float, float} its box: its text and 5 all round */
    private function box(): array
    {
        [$width, $height] = $this->style->measure($this->value);

        return [$width + 10, $height + 10];
    }

    /** @return array{float, float} */
    private function noteSize(?string $comment): array
    {
        return null === $comment ? [0.0, 0.0] : $this->note($comment)->size();
    }

    /** The room a note takes above or below the box: its height and 20 to the box. */
    private function noteRoom(?string $comment): float
    {
        return null === $comment ? 0.0 : $this->noteSize($comment)[1] + 20;
    }

    private function note(string $comment): NoteBox
    {
        return $this->style->note($comment);
    }
}
