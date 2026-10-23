<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Files;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlEmoji;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A files diagram drawn as PlantUML's `FilesListing` draws it: each entry a row, its emoji —
 * an open folder or a page, one and a half times the font's size, standing an eighth of it
 * below the text's foot — and its name; 2 between rows, each folder's entries 21 further in;
 * a note as PlantUML's opale, in the `note` style.
 */
final class FilesDrawing
{
    private const float INDENT = 21.0;
    private const float GAP = 2.0;
    /** PlantUML's sans-serif line, and what of it lies below the foot, against its size. */
    private const float SANS = 1.177_734_375;
    private const float DESCENT = 0.210_937_5;

    /** @var list<Mark> */
    private array $marks = [];
    private float $width = 0.0;
    private float $y = 0.0;

    private function __construct(
        private readonly Style $style,
        private readonly Style $note,
    ) {}

    public static function draw(PlantUmlFiles $files): Drawing
    {
        $styles = $files->document->styles;
        $drawing = new self($styles->style(['root', 'element', 'filesDiagram']), $styles->style(['root', 'element', 'filesDiagram', 'note']));
        foreach ($files->root->children as $entry) {
            $drawing->entry($entry, 0.0);
        }

        return PlantUmlFrame::drawing($files->document, $drawing->marks, $drawing->width, $drawing->y);
    }

    private function entry(FilesEntry $entry, float $x): void
    {
        if (FilesType::Note === $entry->type) {
            $box = new NoteBox(PlantUmlText::styled($entry->note, $this->note), $this->note);
            [$width, $height] = $box->size();
            \array_push($this->marks, ...$box->marks($x, $this->y));
            $this->width = \max($this->width, $x + $width);
            $this->y += $height + self::GAP;

            return;
        }
        $size = $this->style->fontSize();
        $emoji = 1.5 * $size;
        \array_push($this->marks, ...PlantUmlEmoji::marks($entry->type->emoji(), $x, $this->y, $emoji));
        // As PlantUML's `getWithNewlines`, a `\n` in the name breaks it; the first line beside the emoji.
        $foot = $this->y + $emoji + ($size / 8) - ($size * self::DESCENT);
        foreach (\explode('\n', $entry->name) as $index => $line) {
            $text = new PlantUmlText([$line], $size, $this->style->face(), $this->style->color('FontColor', '#000000'));
            $left = $x + (0 === $index ? $emoji : 0.0);
            if ($index > 0) {
                $foot += $size * self::SANS;
            }
            \array_push($this->marks, ...$text->marks($left, $foot - $text->ascent(), $text->size()[0], 'left'));
            $this->width = \max($this->width, $left + $text->size()[0]);
        }
        $this->y = $foot + ($size * self::DESCENT) + self::GAP;
        foreach ($entry->children as $child) {
            $this->entry($child, $x + self::INDENT);
        }
    }
}
