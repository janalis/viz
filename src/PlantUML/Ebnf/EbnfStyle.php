<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Text\FontFace;

/**
 * The style an EBNF or regex diagram is drawn in, as PlantUML's `ETile` takes it — the
 * `ebnf` element's font and colours, its notes' — and its text set as PlantUML's `UText`
 * sets it: a single line, without Creole, measured in the font's line.
 */
final readonly class EbnfStyle
{
    /** How far below its foot a line's baseline stands, for each point of font size, as PlantUML measures its font. */
    public const float DESCENT = 0.210_937_5;

    public Style $style;
    public float $size;
    public string $line;
    public string $back;
    public string $color;

    public function __construct(
        public StyleSheet $sheet,
    ) {
        $this->style = $sheet->style(['root', 'element', 'ebnf']);
        $this->size = $this->style->fontSize();
        $this->line = $this->style->color('LineColor', '#181818');
        $this->back = $this->style->color('BackGroundColor', '#f1f1f1');
        $this->color = $this->style->color('FontColor', '#000000');
    }

    /** @return array{float, float} a text's size at a font size, the style's by default, as PlantUML measures it in its face */
    public function measure(string $text, ?float $size = null, FontFace $face = FontFace::Regular): array
    {
        $size ??= $this->size;

        return [PlantUmlMetrics::width($text, $size, $face), PlantUmlText::lineHeight($size)];
    }

    public function descent(?float $size = null): float
    {
        return ($size ?? $this->size) * self::DESCENT;
    }

    /** @return list<Mark> a text, its top left at a point; nothing for a blank one */
    public function word(string $text, float $x, float $top, ?float $size = null, FontFace $face = FontFace::Regular): array
    {
        $size ??= $this->size;
        if ('' === \trim($text) || 'none' === $this->color) {
            return [];
        }
        [$width, $height] = $this->measure($text, $size, $face);

        return [new Mark(
            MarkKind::Text,
            [[$x + ($width / 2), $top + $height - $this->descent($size)]],
            $text,
            stroke: $this->color,
            font: $face,
            fontScale: Units::fontScale($size) / Units::POINT,
            slots: PlantUmlMetrics::slots($text, $size, $face),
        )];
    }

    /**
     * A comment as PlantUML's `FloatingNote` draws it, in the `ebnf` note's style: its text
     * split at `\n` written out, or its lines.
     *
     * @param string|list<string> $text
     */
    public function note(string|array $text): NoteBox
    {
        $style = $this->sheet->style(['root', 'element', 'ebnf', 'note']);

        return new NoteBox(PlantUmlText::styled(\is_array($text) ? $text : \explode('\n', $text), $style), $style);
    }
}
