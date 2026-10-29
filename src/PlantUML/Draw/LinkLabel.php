<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
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
    /** The room before a pointed label's text, for its small triangle, as measured on PlantUML's output. */
    public const float POINTED = 13.0;

    private function __construct(
        private PlantUmlText $text,
        private ?NoteShape $note,
        private string $position,
        /** The room before the text: for the small triangle a label written `label >` or `< label` starts with. */
        private float $lead = 0.0,
    ) {}

    /**
     * A link's label in its arrow's style, with the last note written on it in the note style.
     *
     * @param list<string> $lines
     * @param list<PlantUmlNote> $notes the notes on this link
     * @param float $lead the room before the text: {@see POINTED} for a label written `label >` or `< label`, none else
     */
    public static function of(array $lines, Style $arrow, array $notes, Style $noteStyle, float $lead = 0.0): self
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

        return new self(PlantUmlText::styled($lines, $arrow), $box, $note->position ?? 'bottom', $lead);
    }

    public function empty(): bool
    {
        return $this->text->empty() && null === $this->note;
    }

    /**
     * The box svek writes for it: its text with the room given around it, the note merged
     * below, above or beside it, each centred, as PlantUML's `SvekEdge` merges them.
     *
     * @param float $room the room around the text, both sides together: {@see Svek::labelMargin()}
     * @return array{float, float}
     */
    public function size(float $room = 0.0): array
    {
        [$width, $height] = $this->layout($room);

        return [$width, $height];
    }

    /**
     * @param float $room the room around the text, both sides together
     * @return list<Mark> with the top left of the box svek writes for it at a point
     */
    public function marks(float $x, float $y, float $room = 0.0): array
    {
        [, , [$textX, $textY], [$noteX, $noteY]] = $this->layout($room);
        [$textWidth] = $this->textSize();
        $marks = [...$this->text->marks($x + $textX + $this->lead, $y + $textY, $textWidth - $this->lead), ...$this->room($x + $textX, $y + $textY, $room / 2)];
        if (null === $this->note) {
            return $marks;
        }
        [$noteWidth, $noteHeight] = $this->note->size();

        // As svek draws a note on a link: as paths, which PlantUML's limit finder takes as they run.
        $note = \array_map(
            static fn (Mark $mark): Mark => MarkKind::Polygon === $mark->kind ? $mark->with(['outline' => Outline::Path]) : $mark,
            $this->note->marks($x + $noteX, $y + $noteY, new Area($noteWidth, $noteHeight), Layer::Foreground),
        );

        return [...$marks, ...$note];
    }

    /**
     * @return array{float, float, array{float, float}, array{float, float}} its width and height, and where its text and its note stand in it
     */
    private function layout(float $room): array
    {
        [$textWidth, $textHeight] = $this->textSize();
        // As PlantUML, the room is around the text alone.
        [$blockWidth, $blockHeight] = $this->text->empty() ? [0.0, 0.0] : [$textWidth + $room, $textHeight + $room];
        $inset = $this->text->empty() ? 0.0 : $room / 2;
        [$noteWidth, $noteHeight] = $this->note?->size() ?? [0.0, 0.0];
        $across = null !== $this->note && \in_array($this->position, ['left', 'right'], strict: true);
        [$width, $height] = $across ? [$blockWidth + $noteWidth, \max($blockHeight, $noteHeight)] : [\max($blockWidth, $noteWidth), $blockHeight + $noteHeight];
        [$block, $note] = match (true) {
            null === $this->note => [[0.0, 0.0], [0.0, 0.0]],
            'top' === $this->position => [[($width - $blockWidth) / 2, $noteHeight], [($width - $noteWidth) / 2, 0.0]],
            'left' === $this->position => [[$noteWidth, ($height - $blockHeight) / 2], [0.0, ($height - $noteHeight) / 2]],
            'right' === $this->position => [[0.0, ($height - $blockHeight) / 2], [$blockWidth, ($height - $noteHeight) / 2]],
            default => [[($width - $blockWidth) / 2, 0.0], [($width - $noteWidth) / 2, $blockHeight]],
        };

        return [$width, $height, [$block[0] + $inset, $block[1] + $inset], $note];
    }

    /** @return list<Mark> an outline, drawn nothing, as far round the text as the room taken around it, as PlantUML's limit finder takes a margined block */
    private function room(float $x, float $y, float $room): array
    {
        if ($this->text->empty() || $room <= 0) {
            return [];
        }
        [$width, $height] = $this->textSize();

        return [new Mark(
            MarkKind::Polygon,
            [[$x - $room, $y - $room], [$x + $width + $room, $y - $room], [$x + $width + $room, $y + $height + $room], [$x - $room, $y + $height + $room]],
            stroke: 'none',
            fill: 'none',
            strokeWidth: 0.0,
            outline: Outline::Path,
        )];
    }

    /** @return array{float, float} the text's size, with the room before it */
    private function textSize(): array
    {
        [$width, $height] = $this->text->size();

        return [$this->text->empty() ? 0.0 : $width + $this->lead, $height];
    }
}
