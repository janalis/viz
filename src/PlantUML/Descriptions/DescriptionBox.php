<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\States\StateBox;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Text\FontFace;

/**
 * An element drawn with its PlantUML symbol, in points: a box shaped by its symbol with its
 * text inside, within the symbol's margins — or for an actor, a person, the robustness icons
 * and an interface, the icon above or beside its name; a use case as an ellipse around its text.
 */
final readonly class DescriptionBox
{
    /**
     * Each boxed symbol's margins around its text: left, right, top, bottom, as PlantUML's symbols keep them.
     *
     * @var array<string, array{int, int, int, int}>
     */
    private const array MARGINS = [
        'action' => [10, 20, 10, 10],
        'artifact' => [10, 20, 13, 10],
        'card' => [10, 10, 3, 3],
        'cloud' => [15, 15, 15, 15],
        'collections' => [10, 10, 10, 10],
        'component' => [15, 25, 20, 10],
        'database' => [10, 10, 24, 5],
        'file' => [10, 10, 10, 10],
        'folder' => [10, 20, 13, 10],
        'frame' => [15, 25, 20, 10],
        'hexagon' => [10, 10, 10, 10],
        'label' => [10, 10, 10, 10],
        'node' => [15, 25, 20, 10],
        'person' => [10, 10, 10, 10],
        'process' => [20, 20, 10, 10],
        'queue' => [5, 15, 5, 5],
        'rectangle' => [10, 10, 10, 10],
        'agent' => [10, 10, 10, 10],
        'package' => [10, 10, 10, 10],
        'stack' => [25, 25, 10, 10],
        'storage' => [10, 10, 10, 10],
    ];
    /**
     * The symbols drawn as an icon with the name below, and the icon's size.
     *
     * @var array<string, array{float, float}>
     */
    private const array ICONS = ['actor' => [28.0, 60.0], 'boundary' => [30.0, 22.0], 'control' => [22.0, 22.0], 'entity' => [22.0, 22.0], 'interface' => [10.0, 10.0], 'circle' => [10.0, 10.0]];

    private function __construct(
        private DescriptionElement $element,
        private Style $style,
        private PlantUmlText $text,
        private ?PlantUmlText $stereotypes,
        public float $width,
        public float $height,
    ) {}

    public static function of(DescriptionElement $element, StyleSheet $sheet): self
    {
        $style = $sheet->style(['root', 'element', 'componentDiagram', $element->symbol], $element->stereotypes);
        $text = PlantUmlText::styled($element->display, $style);
        $stereotypes = [] === $element->stereotypes ? null : new PlantUmlText(\array_map(static fn (string $label): string => '«'.$label.'»', $element->stereotypes), 12.0, FontFace::Italic);
        [$textWidth, $textHeight] = $text->size();
        [$stereoWidth, $stereoHeight] = null === $stereotypes ? [0.0, 0.0] : $stereotypes->size();
        $labelWidth = \max($textWidth, $stereoWidth);
        $labelHeight = $textHeight + $stereoHeight;
        [$width, $height] = match (true) {
            'usecase' === $element->symbol => [($labelWidth * \M_SQRT2) + 20, ($labelHeight * \M_SQRT2) + 10],
            \in_array($element->symbol, ['interface', 'circle'], strict: true) => self::ICONS[$element->symbol],
            \array_key_exists($element->symbol, self::ICONS) => [\max(self::ICONS[$element->symbol][0], $labelWidth), self::ICONS[$element->symbol][1] + $labelHeight + 4],
            default => self::boxed($element->symbol, $labelWidth, $labelHeight),
        };

        return new self($element, $style, $text, $stereotypes, $width, $height);
    }

    /** @return array{float, float} */
    private static function boxed(string $symbol, float $width, float $height): array
    {
        [$left, $right, $top, $bottom] = self::MARGINS[$symbol] ?? self::MARGINS['rectangle'];

        return [$width + $left + $right, $height + $top + $bottom];
    }

    /** @return list<Mark> the element with its top left at a point */
    public function marks(float $x, float $y): array
    {
        $line = ClassColors::line($this->element->color) ?? $this->style->color('LineColor', '#181818');
        $back = ClassColors::back($this->element->color) ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $stroke = $this->style->number('LineThickness', 0.5);
        $symbol = $this->element->symbol;
        if (\array_key_exists($symbol, self::ICONS)) {
            return $this->icon($symbol, $x, $y, $line, $back, $stroke);
        }
        [$left, $right, $top] = self::MARGINS[$symbol] ?? [10, 10, 10, 10];
        $outline = 'usecase' === $symbol
            ? [self::ellipse($x, $y, $this->width, $this->height, $line, $back, $stroke)]
            : DescriptionShapes::outline($symbol, $x, $y, $this->width, $this->height, $line, $back, $stroke, $this->style->number('RoundCorner'));
        [$labelLeft, $labelTop, $labelWidth] = 'usecase' === $symbol
            ? [$x, $y + (($this->height - $this->labelHeight()) / 2), $this->width]
            : [$x + $left, $y + $top, $this->width - $left - $right];

        return [...$outline, ...$this->label($labelLeft, $labelTop, $labelWidth)];
    }

    /** @return list<Mark> an icon above its centred name */
    private function icon(string $symbol, float $x, float $y, string $line, string $back, float $stroke): array
    {
        $centre = $x + ($this->width / 2);
        [, $iconHeight] = self::ICONS[$symbol];
        $marks = match ($symbol) {
            'actor' => self::stickman($centre, $y, $line, $back, $stroke),
            'boundary' => [
                StateBox::disc($centre + 4, $y + 11, 11.0, $line, $back),
                new Mark(MarkKind::Polyline, [[$centre - 15, $y], [$centre - 15, $y + 22]], stroke: $line, strokeWidth: $stroke),
                new Mark(MarkKind::Polyline, [[$centre - 15, $y + 11], [$centre - 7, $y + 11]], stroke: $line, strokeWidth: $stroke),
            ],
            'control' => [
                StateBox::disc($centre, $y + 11, 11.0, $line, $back),
                new Mark(MarkKind::Polygon, [[$centre - 2, $y - 3], [$centre + 4, $y], [$centre - 2, $y + 3]], stroke: $line, fill: $line, strokeWidth: $stroke),
            ],
            'entity' => [StateBox::disc($centre, $y + 11, 11.0, $line, $back), new Mark(MarkKind::Polyline, [[$centre - 11, $y + 22], [$centre + 11, $y + 22]], stroke: $line, strokeWidth: $stroke)],
            default => [StateBox::disc($centre, $y + 5, 5.0, $line, $back)],
        };
        $top = $y + $iconHeight + 4;

        return [...$marks, ...$this->label($x, $top, $this->width)];
    }

    /** @return list<Mark> the stereotypes above the text, both centred within a width */
    private function label(float $x, float $y, float $width): array
    {
        $marks = [];
        if (null !== $this->stereotypes) {
            \array_push($marks, ...$this->stereotypes->marks($x, $y, $width));
            $y += $this->stereotypes->size()[1];
        }

        return [...$marks, ...$this->text->marks($x, $y, $width)];
    }

    private function labelHeight(): float
    {
        return $this->text->size()[1] + (null === $this->stereotypes ? 0.0 : $this->stereotypes->size()[1]);
    }

    /** @return list<Mark> PlantUML's stick figure, its head at the top */
    private static function stickman(float $x, float $y, string $line, string $back, float $stroke): array
    {
        $stroke = \max($stroke, 1.0);

        return [
            StateBox::disc($x, $y + 9, 8.0, $line, $back),
            new Mark(MarkKind::Polyline, [[$x, $y + 17], [$x, $y + 44]], stroke: $line, strokeWidth: $stroke),
            new Mark(MarkKind::Polyline, [[$x - 13, $y + 25], [$x + 13, $y + 25]], stroke: $line, strokeWidth: $stroke),
            new Mark(MarkKind::Polyline, [[$x - 13, $y + 59], [$x, $y + 44], [$x + 13, $y + 59]], stroke: $line, strokeWidth: $stroke),
        ];
    }

    public static function ellipse(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke): Mark
    {
        $points = [];
        for ($step = 0; $step < 48; ++$step) {
            $angle = (2 * \M_PI * $step) / 48;
            $points[] = [$x + ($width / 2) + (($width / 2) * \cos($angle)), $y + ($height / 2) + (($height / 2) * \sin($angle))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: $line, fill: $back, strokeWidth: $stroke);
    }
}
