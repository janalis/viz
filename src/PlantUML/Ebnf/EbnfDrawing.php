<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\Text\FontFace;

/**
 * EBNF and regex diagrams drawn as PlantUML draws them: an EBNF diagram's rules and notes one
 * under the other on the left — each rule its name in bold, between the notes written before
 * and after it, over its railroad, 10 under it and 15 over the next; each note 5 under the
 * one before and 15 over the next; a rule PlantUML cannot build as `Syntax error!` — and a
 * regex's railroad 5 in all round, in lines 1 thick. In points, set in the document's frame.
 */
final class EbnfDrawing
{
    private const string SYNTAX_ERROR = 'Syntax error!';

    /** @var list<Mark> */
    private array $marks = [];
    private float $width = 0.0;
    private float $height = 0.0;

    private function __construct(
        private readonly EbnfStyle $style,
    ) {}

    public static function ebnf(PlantUmlEbnf $diagram): Drawing
    {
        $drawing = new self(new EbnfStyle($diagram->document->styles));
        foreach ($diagram->items as $item) {
            $item instanceof EbnfRule ? $drawing->rule($item) : $drawing->note($item);
        }
        if ([] === $diagram->items) {
            $drawing->error();
        }

        return PlantUmlFrame::drawing($diagram->document, $drawing->marks, $drawing->width, $drawing->height);
    }

    public static function regex(PlantUmlRegex $diagram): Drawing
    {
        $style = new EbnfStyle($diagram->document->styles);
        $tile = $diagram->tile;

        return PlantUmlFrame::drawing($diagram->document, $tile->marks(5.0, 5.0, new EbnfPen($style->line, 1.0)), $tile->width() + 10, $tile->height() + 10);
    }

    private function rule(EbnfRule $rule): void
    {
        $tile = $rule->expression->tile($this->style);
        $name = $rule->expression->name();
        if (null === $tile || null === $name) {
            $this->error();

            return;
        }
        // The name between its notes, all centred on the widest.
        $parts = [];
        if (null !== $rule->above) {
            $parts[] = $this->style->note($rule->above);
        }
        $parts[] = $name;
        if (null !== $rule->below) {
            $parts[] = $this->style->note($rule->below);
        }
        $sizes = \array_map(fn ($part): array => \is_string($part) ? $this->style->measure($part, face: FontFace::Bold) : $part->size(), $parts);
        $widest = \max(\array_column($sizes, 0));
        $top = $this->height;
        foreach ($parts as $index => $part) {
            [$partWidth, $partHeight] = $sizes[$index];
            $left = ($widest - $partWidth) / 2;
            \array_push($this->marks, ...\is_string($part) ? $this->style->word($part, $left, $top, face: FontFace::Bold) : $part->marks($left, $top));
            $top += $partHeight;
        }
        \array_push($this->marks, ...$tile->marks(0.0, $top + 10, new EbnfPen($this->style->line, 1.0)));
        $this->width = \max($this->width, $widest, $tile->width());
        $this->height = $top + 10 + $tile->height() + 15;
    }

    /** @param list<string> $lines */
    private function note(array $lines): void
    {
        $note = $this->style->note($lines);
        [$width, $height] = $note->size();
        \array_push($this->marks, ...$note->marks(0.0, $this->height + 5));
        $this->width = \max($this->width, $width);
        $this->height += 5 + $height + 15;
    }

    /** As PlantUML's `syntaxError`: the words in the diagram's font, on the left. */
    private function error(): void
    {
        [$width, $height] = $this->style->measure(self::SYNTAX_ERROR);
        \array_push($this->marks, ...$this->style->word(self::SYNTAX_ERROR, 0.0, $this->height));
        $this->width = \max($this->width, $width);
        $this->height += $height;
    }
}
