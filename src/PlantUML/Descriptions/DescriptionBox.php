<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\States\StateBox;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

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
        'collections' => [8, 12, 8, 12],
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
    private const array ICONS = ['actor' => [28.0, 60.0], 'boundary' => [49.0, 32.0], 'control' => [32.0, 32.0], 'entity' => [32.0, 32.0], 'interface' => [18.0, 18.0], 'circle' => [18.0, 18.0]];

    /** The room a folder keeps for its title, which an element does not show. */
    private const array FOLDER_TITLE = [40.0, 15.0];

    private function __construct(
        private DescriptionElement $element,
        private Style $style,
        private PlantUmlText $text,
        private ?PlantUmlText $stereotypes,
        public float $width,
        public float $height,
        /** Whether its lines are set left in their block, the block centred, as a network diagram's servers set them; each line centred otherwise. */
        private bool $flushLeft = false,
        /** Whether an interface is its circle alone, its name hanging below, as svek writes one linked beside another. */
        private bool $alone = false,
    ) {}

    /** An interface as svek writes one a link joins beside another: its circle alone, 18 across, its name hanging 8 below. */
    public function beside(): self
    {
        return \in_array($this->element->symbol, ['interface', 'circle'], strict: true) ? clone($this, ['width' => 18.0, 'height' => 18.0, 'alone' => true]) : $this;
    }

    /**
     * @param ?Style $style the style it is drawn in, its symbol's in a component diagram by default, as a network diagram's servers take their own
     * @param bool $flushLeft whether its lines are set left in their block, the block centred
     */
    public static function of(DescriptionElement $element, StyleSheet $sheet, ?Style $style = null, bool $flushLeft = false): self
    {
        $style ??= $sheet->style(['root', 'element', 'componentDiagram', $element->symbol], $element->stereotypes);
        $text = PlantUmlText::styled($element->display, $style);
        $stereoStyle = $sheet->style(['root', 'element', 'componentDiagram', $element->symbol, 'stereotype'], $element->stereotypes);
        $stereotypes = [] === $element->stereotypes || !$element->stereotypeShown
            ? null
            : PlantUmlText::styled(\array_map(static fn (string $label): string => '«'.$label.'»', $element->stereotypes), $stereoStyle);
        [$textWidth, $textHeight] = $text->size();
        [$stereoWidth, $stereoHeight] = null === $stereotypes ? [0.0, 0.0] : $stereotypes->size();
        $labelWidth = \max($textWidth, $stereoWidth);
        $labelHeight = $textHeight + $stereoHeight;
        [$width, $height] = match (true) {
            'usecase' === $element->symbol => self::inEllipse($labelWidth, $labelHeight),
            // As PlantUML: its flat sides as wide as its text, its points half as far again, 5 above and below.
            'hexagon' === $element->symbol => [2 * $labelWidth, $labelHeight + 10],
            'person' === $element->symbol => self::person($labelWidth + 20, $labelHeight + 20),
            // As svek writes an interface, and dot sizes it: its circle in a cell 18 across, its name's height above and below and as wide as its name, in whole points, 4 around, 54 wide at least.
            \in_array($element->symbol, ['interface', 'circle'], strict: true) => [\max(54.0, 18 + (2 * \round(\max(0.5, ($labelWidth - 18) / 2))) + 8), (2 * \round($labelHeight)) + 26],
            \array_key_exists($element->symbol, self::ICONS) => [\max(self::ICONS[$element->symbol][0], $labelWidth), self::ICONS[$element->symbol][1] + $labelHeight],
            // As PlantUML, a folder keeps room for a title it does not show, 40 by 15, above its label.
            'folder' === $element->symbol => self::boxed($element->symbol, \max(self::FOLDER_TITLE[0], $labelWidth), self::FOLDER_TITLE[1] + $labelHeight),
            default => self::boxed($element->symbol, $labelWidth, $labelHeight),
        };

        return new self($element, $style, $text, $stereotypes, $width, $height, $flushLeft);
    }

    /** @return array{float, float} */
    /** @return array{float, float} a person's size: its body around its text, its head above it */
    private static function person(float $width, float $height): array
    {
        return [$width, $height + (2 * DescriptionShapes::radius($width, $height))];
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
        [$labelLeft, $labelTop, $labelWidth] = match ($symbol) {
            'usecase' => [$x, $y + (($this->height - $this->labelHeight()) / 2), $this->width],
            'hexagon' => [$x + ($this->width / 4), $y + 5, $this->width / 2],
            'person' => [$x + $left, $y + (2 * DescriptionShapes::head($this->width, $this->height)) + $top, $this->width - $left - $right],
            default => [$x + $left, $y + $top + ('folder' === $symbol ? self::FOLDER_TITLE[1] : 0.0), $this->width - $left - $right],
        };

        return [...Shadow::cast($outline, $this->style->number('Shadowing')), ...$this->label($labelLeft, $labelTop, $labelWidth)];
    }

    /** @return list<Mark> an icon above its centred name */
    private function icon(string $symbol, float $x, float $y, string $line, string $back, float $stroke): array
    {
        $centre = $x + ($this->width / 2);
        $marks = match ($symbol) {
            'actor' => self::stickman($centre, $y, $line, $back, $stroke),
            // As PlantUML 1.2026 draws them, each a circle 24 across 16 below its node's top: beside a bar 24 tall, over a line, under an arrowhead.
            'boundary' => [
                StateBox::disc($centre + 4.5, $y + 16, 12.0, $line, $back),
                new Mark(MarkKind::Polyline, [[$centre - 24.5, $y + 4], [$centre - 24.5, $y + 28]], stroke: $line, strokeWidth: $stroke),
                new Mark(MarkKind::Polyline, [[$centre - 24.5, $y + 16], [$centre - 7.5, $y + 16]], stroke: $line, strokeWidth: $stroke),
            ],
            'control' => [
                StateBox::disc($centre, $y + 16, 12.0, $line, $back),
                new Mark(
                    MarkKind::Polygon,
                    [[$centre - 4, $y + 4], [$centre + 2, $y - 1], [$centre, $y + 4], [$centre + 2, $y + 9]],
                    stroke: $line,
                    fill: $line,
                    strokeWidth: $stroke,
                    outline: Outline::Path,
                ),
            ],
            'entity' => [StateBox::disc($centre, $y + 16, 12.0, $line, $back), new Mark(MarkKind::Polyline, [[$centre - 12, $y + 30], [$centre + 12, $y + 30]], stroke: $line, strokeWidth: $stroke)],
            // As PlantUML's interface: a circle 16 across at its node's centre.
            default => [StateBox::disc($centre, $y + ($this->height / 2), 8.0, $line, $back)],
        };
        // An interface's name 17 below its circle's centre, 8 below its node alone; another's under its icon.
        $top = $y
        + match (true) {
            $this->alone => 26.0,
            \in_array($symbol, ['interface', 'circle'], strict: true) => ($this->height / 2) + 17,
            default => self::ICONS[$symbol][1],
        };

        return [...$marks, ...$this->label($x, $top, $this->width)];
    }

    /**
     * The ellipse PlantUML's `TextBlockInEllipse` fits around a text: the smallest around it,
     * as flat as the text but between a fifth and four fifths as tall as wide, 6 bigger.
     *
     * @return array{float, float}
     */
    public static function inEllipse(float $width, float $height): array
    {
        $aspect = $width > 0 ? \min(0.8, \max(0.2, $height / $width)) : 0.8;
        $half = \hypot($width / 2, $height / (2 * $aspect));

        return [(2 * $half) + 6, (2 * $half * $aspect) + 6];
    }

    /** @return list<Mark> the stereotypes above the text, both centred within a width */
    private function label(float $x, float $y, float $width): array
    {
        $marks = [];
        if (null !== $this->stereotypes) {
            \array_push($marks, ...$this->stereotypes->marks($x, $y, $width));
            $y += $this->stereotypes->size()[1];
        }

        if ($this->flushLeft) {
            $textWidth = $this->text->size()[0];

            return [...$marks, ...$this->text->marks($x + (($width - $textWidth) / 2), $y, $textWidth, 'left')];
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

        // As PlantUML draws it, half a point below its node's top, as measured on its output.
        $y += 0.5;

        return [
            StateBox::disc($x, $y + 8, 8.0, $line, $back),
            new Mark(MarkKind::Polyline, [[$x, $y + 16], [$x, $y + 43]], stroke: $line, strokeWidth: $stroke),
            new Mark(MarkKind::Polyline, [[$x - 13, $y + 24], [$x + 13, $y + 24]], stroke: $line, strokeWidth: $stroke),
            new Mark(MarkKind::Polyline, [[$x - 13, $y + 58], [$x, $y + 43], [$x + 13, $y + 58]], stroke: $line, strokeWidth: $stroke),
        ];
    }

    public static function ellipse(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke): Mark
    {
        $points = [];
        for ($step = 0; $step < 48; ++$step) {
            $angle = (2 * \M_PI * $step) / 48;
            $points[] = [$x + ($width / 2) + (($width / 2) * \cos($angle)), $y + ($height / 2) + (($height / 2) * \sin($angle))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: $line, fill: $back, strokeWidth: $stroke, outline: Outline::Ellipse);
    }
}
