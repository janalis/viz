<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/** What every player's panels draw alike, as PlantUML's `Panels` draws it: constraints and notes. */
final readonly class TimingParts
{
    public function __construct(
        public TimingRuler $ruler,
        public StyleSheet $sheet,
    ) {}

    /** A constraint's text's height, and 5 above it. */
    public function constraintHeight(TimingConstraint $constraint): float
    {
        return $this->constraintText($constraint)->size()[1] + 5;
    }

    /**
     * PlantUML's `TimeConstraint.drawU`: a line between the moments, a margin in from each,
     * its heads outside when they are 20 or less apart; its text above, centred.
     *
     * @return list<Mark> its line at a height, the time axis's start at a point
     */
    public function constraint(TimingConstraint $constraint, float $x, float $y): array
    {
        $style = $this->sheet->style(['root', 'element', 'timingDiagram', 'constraintArrow']);
        $color = $constraint->color ?? $style->color('LineColor', '#8b0000');
        $x1 = $x + $this->ruler->x($constraint->from->time) + $constraint->margin;
        $x2 = $x + $this->ruler->x($constraint->to->time) - $constraint->margin;
        $apart = ($x2 - $x1) > 20;
        $marks = [
            new Mark(MarkKind::Polyline, $apart ? [[$x1 + 3, $y], [$x2 - 3, $y]] : [[$x1 - 1, $y], [$x2 + 1, $y]], stroke: $color, strokeWidth: $style->number('LineThickness', 1.5)),
            self::head($x1, $y, $apart ? -1.0 : 1.0, $color),
            self::head($x2, $y, $apart ? 1.0 : -1.0, $color),
        ];
        $text = $this->constraintText($constraint);

        return [...$marks, ...$text->marks($x1 + (($x2 - $x1 - $text->size()[0]) / 2), $y - $this->constraintHeight($constraint))];
    }

    /** The tallest note `top` or `bottom`, and 10. */
    public function notesHeight(TimingPlayer $player, string $position): float
    {
        $height = 0.0;
        foreach ($player->notes as $note) {
            if ($note->position !== $position) {
                continue;
            }
            $height = \max($height, $this->note($note)->size()[1] + 10);
        }

        return $height;
    }

    /** @return list<Mark> the notes `top` or `bottom`, each at its moment, from a point; those below 5 lower */
    public function notes(TimingPlayer $player, string $position, float $x, float $y): array
    {
        $marks = [];
        foreach ($player->notes as $note) {
            if ($note->position !== $position) {
                continue;
            }
            $left = $x + (null === $note->when ? 0.0 : $this->ruler->x($note->when->time));
            \array_push($marks, ...$this->note($note)->marks($left, $y + ('top' === $position ? 0.0 : 5.0)));
        }

        return $marks;
    }

    /** A text in the timing diagram's style, or in one of its parts', each `\n` a new line. */
    public function text(string $text, ?string $kind = null): TimingText
    {
        return new TimingText(
            \explode("\n", \str_replace(['\n', '\l', '\r'], replace: "\n", subject: $text)),
            $this->sheet->style(['root', 'element', 'timingDiagram', ...(null === $kind ? [] : [$kind])]),
        );
    }

    private function constraintText(TimingConstraint $constraint): TimingText
    {
        return new TimingText($constraint->label, $this->sheet->style(['root', 'element', 'timingDiagram', 'constraintArrow']));
    }

    private function note(TimingNote $note): NoteBox
    {
        $style = $this->sheet->style(['root', 'element', 'timingDiagram', 'note']);

        return new NoteBox(PlantUmlText::styled($note->lines, $style), $style);
    }

    /** A head 8 long and 8 across, its tip at a point, pointing left (-1) or right (1). */
    private static function head(float $x, float $y, float $direction, string $color): Mark
    {
        $back = $x - (8 * $direction);

        return new Mark(MarkKind::Polygon, [[$back, $y + 4], [$back, $y - 4], [$x, $y]], stroke: $color, fill: $color, strokeWidth: 1.0);
    }
}
