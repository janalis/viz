<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\NoteShape;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\PlantUML\Teoz\Rose\RoseText;

/**
 * What stands by a link's middle, as svek puts it: its label, and a note on the link merged
 * with it — below by default, above, or beside it on its left or right — both centred.
 */
final readonly class LinkLabel
{
    private function __construct(
        private PlantUmlText $text,
        private ?NoteShape $note,
        private string $position,
    ) {}

    /**
     * A link's label in its arrow's style, with the last note written on it in the note style.
     *
     * @param list<string> $lines
     * @param list<PlantUmlNote> $notes the notes on this link
     */
    public static function of(array $lines, Style $arrow, array $notes, Style $noteStyle): self
    {
        $note = [] === $notes ? null : $notes[\count($notes) - 1];
        // As svek, the note is Rose's note component, padded 5 around its sheet.
        $box = null === $note
            ? null
            : new NoteShape(
                'note',
                RoseText::of($note->lines, $noteStyle, NoteShape::padding('note')),
                Pen::of($noteStyle),
                ClassColors::back($note->color) ?? $noteStyle->color('BackGroundColor', '#feffdd'),
            );

        return new self(PlantUmlText::styled($lines, $arrow), $box, $note->position ?? 'bottom');
    }

    public function empty(): bool
    {
        return $this->text->empty() && null === $this->note;
    }

    /** @return array{float, float} */
    public function size(): array
    {
        [$textWidth, $textHeight] = $this->text->size();
        [$noteWidth, $noteHeight] = $this->note?->size() ?? [0.0, 0.0];

        return $this->across() ? [$textWidth + $noteWidth, \max($textHeight, $noteHeight)] : [\max($textWidth, $noteWidth), $textHeight + $noteHeight];
    }

    /** @return list<Mark> with its top left at a point, its parts centred within it */
    public function marks(float $x, float $y, float $width): array
    {
        [$totalWidth, $totalHeight] = $this->size();
        [$textWidth, $textHeight] = $this->text->size();
        if (null === $this->note) {
            return $this->text->marks($x, $y, $width);
        }
        [$noteWidth, $noteHeight] = $this->note->size();
        [$textAt, $noteAt] = match ($this->position) {
            'top' => [[$x + (($totalWidth - $textWidth) / 2), $y + $noteHeight + $this->gap()], [$x + (($totalWidth - $noteWidth) / 2), $y]],
            'left' => [[$x + $noteWidth, $y + (($totalHeight - $textHeight) / 2)], [$x, $y + (($totalHeight - $noteHeight) / 2)]],
            'right' => [[$x, $y + (($totalHeight - $textHeight) / 2)], [$x + $textWidth, $y + (($totalHeight - $noteHeight) / 2)]],
            default => [[$x + (($totalWidth - $textWidth) / 2), $y], [$x + (($totalWidth - $noteWidth) / 2), $y + $textHeight + $this->gap()]],
        };

        return [...$this->text->marks($textAt[0], $textAt[1], $textWidth), ...$this->note->marks($noteAt[0], $noteAt[1], new Area($noteWidth, $noteHeight), Layer::Foreground)];
    }

    /** How far below a label the note under it is drawn, or a label below the note over it, past the room the two take, as measured on PlantUML's output: 1, none without a label. */
    private function gap(): float
    {
        return $this->text->empty() || null === $this->note ? 0.0 : 1.0;
    }

    private function across(): bool
    {
        return \in_array($this->position, ['left', 'right'], strict: true) && null !== $this->note;
    }
}
