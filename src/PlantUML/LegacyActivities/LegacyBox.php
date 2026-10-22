<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\States\StateBox;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * An entity of a legacy activity diagram as PlantUML's svek images draw it: an activity its
 * text 10 clear all round in a box rounded 25, the start a disc 20 across, an end a ring 22
 * across, a branch a diamond 24 across, a bar 80 by 8 — 8 by 80 laid out left to right — and a
 * note with its corner folded.
 */
final readonly class LegacyBox
{
    private const float MARGIN = 10.0;

    private function __construct(
        private LegacyEntity $entity,
        private Style $style,
        private ?PlantUmlText $text,
        private ?NoteBox $note,
        public float $width,
        public float $height,
    ) {}

    public static function of(LegacyEntity $entity, StyleSheet $sheet, Direction $direction): self
    {
        $stereotypes = null === $entity->stereotype ? [] : [\trim($entity->stereotype, characters: '<> ')];
        $path = match ($entity->kind) {
            'activity' => ['root', 'element', 'activityDiagram', 'activity'],
            'branch' => ['root', 'element', 'activityDiagram', 'activity', 'diamond'],
            'bar' => ['root', 'element', 'activityDiagram', 'activityBar'],
            'note' => ['root', 'element', 'activityDiagram', 'note'],
            default => ['root', 'element', 'activityDiagram', 'circle', $entity->kind],
        };
        $style = $sheet->style($path, $stereotypes);
        if ('note' === $entity->kind) {
            $note = new NoteBox(PlantUmlText::styled($entity->display, $style), $style, ClassColors::back($entity->color));
            [$width, $height] = $note->size();

            return new self($entity, $style, null, $note, $width, $height);
        }
        $text = PlantUmlText::styled($entity->display, $style);
        [$width, $height] = match ($entity->kind) {
            'activity' => [$text->size()[0] + (2 * self::MARGIN), $text->size()[1] + (2 * self::MARGIN)],
            'start' => [20.0, 20.0],
            'end' => [22.0, 22.0],
            'branch' => [24.0, 24.0],
            default => Direction::LeftRight === $direction ? [8.0, 80.0] : [80.0, 8.0],
        };

        return new self($entity, $style, $text, null, $width, $height);
    }

    /** How a link stops at it: at its outline, for those not drawn as a box. */
    public function shape(): string
    {
        return match ($this->entity->kind) {
            'start', 'end' => 'oval',
            'branch' => 'diamond',
            default => 'box',
        };
    }

    /**
     * The note with its top left at a point, pointing from where its link leaves it to where the link ends.
     *
     * @param array{float, float} $start
     * @param array{float, float} $tip
     * @return list<Mark>
     */
    public function pointing(float $x, float $y, array $start, array $tip): array
    {
        return null === $this->note ? $this->marks($x, $y) : $this->note->pointing($x, $y, $start, $tip);
    }

    /** @return list<Mark> the entity with its top left at a point */
    public function marks(float $x, float $y): array
    {
        if (null !== $this->note) {
            return $this->note->marks($x, $y);
        }
        $line = $this->style->color('LineColor', '#181818');
        $back = ClassColors::back($this->entity->color) ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $stroke = $this->style->number('LineThickness', 0.5);
        [$cx, $cy] = [$x + ($this->width / 2), $y + ($this->height / 2)];

        return match ($this->entity->kind) {
            'start' => [StateBox::disc($cx, $cy, 10.0, $back, $back)],
            'end' => [StateBox::disc($cx, $cy, 11.0, $back, 'none'), StateBox::disc($cx, $cy, 6.0, $back, $back)],
            'branch' => [new Mark(MarkKind::Polygon, [[$cx, $y], [$x + $this->width, $cy], [$cx, $y + $this->height], [$x, $cy]], stroke: $line, fill: $back, strokeWidth: $stroke)],
            'bar' => [new Mark(MarkKind::Polygon, ClassBox::corners($x, $y, $this->width, $this->height), stroke: 'none', fill: $back, strokeWidth: 0.0)],
            default => [
                ...Shadow::cast([ClassBox::rectangle($x, $y, $this->width, $this->height, $line, $back, $stroke, 25.0)], $this->style->number('Shadowing')),
                ...(null === $this->text ? [] : $this->text->marks($x + self::MARGIN, $y + self::MARGIN, $this->width - (2 * self::MARGIN), 'left')),
            ],
        };
    }
}
