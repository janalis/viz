<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * An element as PlantUML's `EntityImageChen…` draws it: an entity a box 10 and 10 more
 * around its name, doubled 3 inside when `<<weak>>`; a relationship a diamond twice as wide
 * as tall around its name, doubled when `<<identifying>>`; an attribute the ellipse
 * PlantUML's `TextBlockInEllipse` fits around its name, 6 bigger, its name underlined when
 * `<<key>>`, doubled when `<<multi>>`, dashed when `<<derived>>`; a subclass circle 25 across.
 */
final readonly class ChenBox
{
    /** Around an entity's name: PlantUML's margin and the line's. */
    private const float ENTITY_MARGIN = 10.0;
    /** How far below an attribute's top its name stands. */
    private const float ATTRIBUTE_TOP = 6.0;
    private const float CIRCLE = 25.0;

    private function __construct(
        private ChenElement $element,
        private Style $style,
        private PlantUmlText $text,
        public float $width,
        public float $height,
    ) {}

    public static function of(ChenElement $element, StyleSheet $sheet): self
    {
        $names = ['root', 'element', 'chenEerDiagram', $element->kind->value];
        $text = PlantUmlText::styled($element->display, $sheet->style([...$names, 'title'], $element->stereotypes));
        [$textWidth, $textHeight] = $text->size();
        [$width, $height] = match ($element->kind) {
            ChenKind::Entity => [$textWidth + (2 * self::ENTITY_MARGIN), $textHeight + (2 * self::ENTITY_MARGIN)],
            ChenKind::Relationship => self::diamond($textWidth, $textHeight),
            ChenKind::Attribute => DescriptionBox::inEllipse($textWidth, $textHeight),
            ChenKind::Circle => [self::CIRCLE, self::CIRCLE],
        };

        return new self($element, $sheet->style($names, $element->stereotypes), $text, $width, $height);
    }

    /** How a link stops at it: at its outline, for those not drawn as a box. */
    public function shape(): string
    {
        return match ($this->element->kind) {
            ChenKind::Relationship => 'diamond',
            ChenKind::Attribute, ChenKind::Circle => 'oval',
            ChenKind::Entity => 'box',
        };
    }

    /** @return list<Mark> its top left at a point */
    public function marks(float $x, float $y): array
    {
        $line = $this->element->line ?? $this->style->color('LineColor', '#181818');
        $back = $this->element->back ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $stroke = $this->style->number('LineThickness', 0.5);
        [$textWidth, $textHeight] = $this->text->size();
        $element = $this->element;
        $outline = match ($element->kind) {
            ChenKind::Entity => [
                ClassBox::rectangle($x, $y, $this->width, $this->height, $line, $back, $stroke, 0.0),
                ...($element->has('weak') ? [ClassBox::rectangle($x + 3, $y + 3, $this->width - 6, $this->height - 6, $line, $back, $stroke, 0.0)] : []),
            ],
            ChenKind::Relationship => [
                self::rhombus($x, $y, $this->width, $this->height, $line, $back, $stroke),
                ...($element->has('identifying') ? [self::rhombus($x + 10, $y + 5, $this->width - 20, $this->height - 10, $line, $back, $stroke)] : []),
            ],
            ChenKind::Attribute, ChenKind::Circle => [
                self::oval($x, $y, $this->width, $this->height, $line, $back, $stroke),
                ...($element->has('multi') ? [self::oval($x + 3, $y + 3, $this->width - 6, $this->height - 6, $line, $back, $stroke)] : []),
            ],
        };
        $left = $x + (($this->width - $textWidth) / 2);
        $top = $y
        + match ($element->kind) {
            ChenKind::Entity => self::ENTITY_MARGIN,
            ChenKind::Attribute => self::ATTRIBUTE_TOP,
            default => ($this->height - $textHeight) / 2,
        };

        return [...$outline, ...$this->text->marks($left, $top, $textWidth, 'center'), ...$this->underline($left, $top, $textWidth)];
    }

    /** @return array{float, float} as PlantUML sizes a relationship: a diagonal through its name and twice its height, 5 more either side, the diamond twice as wide as tall */
    private static function diamond(float $width, float $height): array
    {
        $diagonal = (($width + (2 * $height)) / \sqrt(5)) + 10;

        return [$diagonal * \sqrt(5), ($diagonal * \sqrt(5)) / 2];
    }

    /** @return list<Mark> a `<<key>>` attribute's name underlined, a line under each of its lines */
    private function underline(float $left, float $top, float $width): array
    {
        if (!$this->element->has('key')) {
            return [];
        }
        $marks = [];
        $line = $this->text->line();
        foreach ($this->text->lines as $index => $_) {
            $under = $top + (($index + 0.85) * $line);
            $marks[] = new Mark(MarkKind::Polyline, [[$left, $under], [$left + $width, $under]], stroke: $this->text->color, strokeWidth: 1.0);
        }

        return $marks;
    }

    private function oval(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke): Mark
    {
        $mark = DescriptionBox::ellipse($x, $y, $width, $height, $line, $back, $stroke);

        return $this->element->has('derived') ? new Mark(MarkKind::Polygon, $mark->points, stroke: $line, fill: $back, dashed: true, strokeWidth: $stroke, dashes: [10.0, 10.0]) : $mark;
    }

    private static function rhombus(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke): Mark
    {
        return new Mark(
            MarkKind::Polygon,
            [[$x, $y + ($height / 2)], [$x + ($width / 2), $y], [$x + $width, $y + ($height / 2)], [$x + ($width / 2), $y + $height]],
            stroke: $line,
            fill: $back,
            strokeWidth: $stroke,
        );
    }
}
