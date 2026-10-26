<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Draw\SvgPath;
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
        'component1' => [10, 10, 10, 10],
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
        'package' => [10, 20, 13, 10],
        'stack' => [25, 25, 10, 10],
        'storage' => [10, 10, 10, 10],
    ];
    /**
     * The symbols drawn as an icon with the name below, and the icon's size.
     *
     * @var array<string, array{float, float}>
     */
    /** `ActorAwesome`'s body, 54 wide and 28 tall, its collar 4 deep, its shoulders and corners rounded. */
    private const array AWESOME = [0.0, 'M0,4 C4,4 7,4 11,0 C19,0 27,8 27,16 L27,20 C27,24 23,28 19,28 L-19,28 C-23,28 -27,24 -27,20 L-27,16 C-27,8 -19,0 -11,0 C-7,4 -4,4 0,4 Z'];
    /** `ActorHollow`'s body, 2 below its head: arms 25 across and 5 thick, a body 6 thick, legs 6 thick at a slant. */
    private const array HOLLOW = [2.0, 'M-12.5,0 L-12.5,5 L-3,5 L-3,7.257 L-12.5,16.757 L-8.257,21 L0,12.743 L8.257,21 L12.5,16.757 L3,7.257 L3,5 L12.5,5 L12.5,0 Z'];
    private const array ICONS = [
        'actor' => [27.0, 60.0],
        'awesome' => [55.0, 61.0],
        'hollow' => [26.0, 33.0],
        'boundary' => [49.0, 32.0],
        'control' => [32.0, 32.0],
        'entity' => [32.0, 32.0],
        'interface' => [18.0, 18.0],
        'circle' => [18.0, 18.0],
    ];

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
        /** A package's title, its name in its tab, 6 room either side. */
        private ?PlantUmlText $title = null,
        /** How far below a use case's top its text starts. */
        private float $textTop = 0.0,
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
        // As PlantUML, a package shows its name in its tab, and its text below only where it is not its name.
        $title = 'package' === $element->symbol ? PlantUmlText::styled([$element->id], $sheet->style(['root', 'element', 'componentDiagram', 'package', 'title'], $element->stereotypes)) : null;
        $text = PlantUmlText::styled(null !== $title && [$element->id] === $element->display ? [] : $element->display, $style);
        $stereoStyle = $sheet->style(['root', 'element', 'componentDiagram', $element->symbol, 'stereotype'], $element->stereotypes);
        $stereotypes = [] === $element->stereotypes || !$element->stereotypeShown
            ? null
            : PlantUmlText::styled(\array_map(static fn (string $label): string => '«'.$label.'»', $element->stereotypes), $stereoStyle);
        [$textWidth, $textHeight] = $text->size();
        [$stereoWidth, $stereoHeight] = null === $stereotypes ? [0.0, 0.0] : $stereotypes->size();
        // As PlantUML is seen to measure it, a stereotype takes 2 more than its text.
        $labelWidth = \max($textWidth, null === $stereotypes ? 0.0 : $stereoWidth + 2);
        $labelHeight = $textHeight + $stereoHeight;
        [$width, $height] = match (true) {
            'usecase' === $element->symbol => self::footprint([...($stereotypes?->rows() ?? []), ...$text->rows()], $element->business ? 7.0 : 0.0),
            // As PlantUML: its flat sides as wide as its text, its points half as far again, 5 above and below.
            'hexagon' === $element->symbol => [2 * $labelWidth, $labelHeight + 10],
            'person' === $element->symbol => self::person($labelWidth + 20, $labelHeight + 20),
            // As svek writes an interface, and dot sizes it: its circle in a cell 18 across, its name's height above and below and as wide as its name, in whole points, 4 around, 54 wide at least; its stereotypes outside it.
            \in_array($element->symbol, ['interface', 'circle'], strict: true) => [\max(54.0, 18 + (2 * \round(\max(0.5, ($labelWidth - 18) / 2))) + 8), (2 * \floor($textHeight)) + 26],
            \array_key_exists($element->shape ?? $element->symbol, self::ICONS) => [
                \max(self::ICONS[$element->shape ?? $element->symbol][0], $labelWidth),
                self::ICONS[$element->shape ?? $element->symbol][1] + $labelHeight,
            ],
            // As PlantUML, a folder keeps room for a title it does not show, 40 by 15, above its label.
            'folder' === $element->symbol => self::boxed($element->symbol, \max(self::FOLDER_TITLE[0], $labelWidth), self::FOLDER_TITLE[1] + $labelHeight),
            null !== $title => self::boxed($element->symbol, \max($title->size()[0] + 12, $labelWidth), $title->size()[1] + $labelHeight),
            default => self::boxed($element->shape ?? $element->symbol, $labelWidth, $labelHeight),
        };

        return new self(
            $element,
            $style,
            $text,
            $stereotypes,
            $width,
            $height,
            $flushLeft,
            title: $title,
            textTop: 'usecase' === $element->symbol ? self::footprint([...($stereotypes?->rows() ?? []), ...$text->rows()], $element->business ? 7.0 : 0.0)[2] : 0.0,
        );
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
        $symbol = $this->element->shape ?? $this->element->symbol;
        if (\array_key_exists($symbol, self::ICONS)) {
            return $this->icon($symbol, $x, $y, $line, $back, $stroke);
        }
        [$left, $right, $top] = self::MARGINS[$symbol] ?? [10, 10, 10, 10];
        $outline = 'usecase' === $symbol
            ? [self::ellipse($x, $y, $this->width, $this->height, $line, $back, $stroke), ...($this->element->business ? [self::across($x, $y, $this->width, $this->height, $line, $stroke)] : [])]
            : DescriptionShapes::outline($symbol, $x, $y, $this->width, $this->height, $line, $back, $stroke, $this->style->number('RoundCorner'), $this->tab());
        [$labelLeft, $labelTop, $labelWidth] = match ($symbol) {
            'usecase' => [$x, $y + $this->textTop, $this->width],
            'hexagon' => [$x + ($this->width / 4), $y + 5, $this->width / 2],
            'person' => [$x + $left, $y + (2 * DescriptionShapes::head($this->width, $this->height)) + $top, $this->width - $left - $right],
            default => [$x + $left, $y + $top + $this->tab()[1], $this->width - $left - $right],
        };
        // A package's title 4 in and 3 down, in its tab.
        $title = null === $this->title ? [] : $this->title->marks($x + 4, $y + 3, $this->tab()[0]);

        return [...Shadow::cast($outline, $this->style->number('Shadowing')), ...$title, ...$this->label($labelLeft, $labelTop, $labelWidth)];
    }

    /** @return array{float, float} the title a folder's or a package's tab holds: a package's name and 6 either side, the 40 by 15 a folder keeps for one it does not show */
    private function tab(): array
    {
        return match (true) {
            null !== $this->title => [$this->title->size()[0] + 12, $this->title->size()[1]],
            'folder' === $this->element->symbol => self::FOLDER_TITLE,
            default => [0.0, 0.0],
        };
    }

    /** @return list<Mark> an icon above its centred name, under its stereotypes but an interface's */
    private function icon(string $symbol, float $x, float $y, string $line, string $back, float $stroke): array
    {
        // As PlantUML's simple symbols stack them: the stereotypes, the icon, the name; an interface's stereotypes ending 17 above its circle's centre, as its name starts 17 below.
        $interface = \in_array($symbol, ['interface', 'circle'], strict: true);
        $stereoHeight = null === $this->stereotypes ? 0.0 : $this->stereotypes->size()[1];
        $stereotypes = null === $this->stereotypes ? [] : $this->stereotypes->marks($x, $interface ? $y + ($this->height / 2) - 17 - $stereoHeight : $y, $this->width);
        $y += $interface ? 0.0 : $stereoHeight;
        $centre = $x + ($this->width / 2);
        $marks = match ($symbol) {
            'actor' => [...self::stickman($centre, $y, $line, $back, $stroke), ...($this->element->business ? [self::chord($centre, $y, $line, $stroke)] : [])],
            'awesome' => self::figure($centre, $y + 0.5, 16.0, self::AWESOME, $line, $back, $stroke),
            'hollow' => self::figure($centre, $y + 0.5, 4.5, self::HOLLOW, $line, $back, $stroke),
            // As PlantUML 1.2026 draws them, 4 in from their node's sides, each a circle 24 across 16 below its node's top: beside a bar 24 tall, over a line, under an arrowhead.
            'boundary' => [
                StateBox::disc($centre + 8.5, $y + 16, 12.0, $line, $back),
                new Mark(MarkKind::Polyline, [[$centre - 20.5, $y + 4], [$centre - 20.5, $y + 28]], stroke: $line, strokeWidth: $stroke),
                new Mark(MarkKind::Polyline, [[$centre - 20.5, $y + 16], [$centre - 3.5, $y + 16]], stroke: $line, strokeWidth: $stroke),
            ],
            'control' => [
                StateBox::disc($centre, $y + 16, 12.0, $line, $back),
                new Mark(
                    MarkKind::Polygon,
                    [[$centre - 4, $y + 4], [$centre + 2, $y - 1], [$centre, $y + 4], [$centre + 2, $y + 9]],
                    stroke: $line,
                    fill: $line,
                    strokeWidth: $stroke,
                    // As PlantUML draws it, a polygon, its limit finder taking it 10 wider either side.
                    outline: Outline::Polygon,
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

        return [...$stereotypes, ...$marks, ...$this->name($x, $top, $this->width)];
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

    /**
     * The ellipse PlantUML's `TextBlockInEllipse` fits around lines centred one under another,
     * as its `Footprint` and `ContainingEllipse` find it: the smallest around each line's corners,
     * as flat as the lines but between a fifth and four fifths as tall as wide, 6 bigger; the
     * lines drawn from its centre, 2 higher. A business use case's lines held 7 in from either side,
     * the block they make, its margins with it, taken whole as well as each line's corners.
     *
     * @param list<array{float, float, float}> $rows each line's width, height and how far above its top it is taken
     * @return array{float, float, float} its width, its height, and how far below its top the lines start
     */
    private static function footprint(array $rows, float $margin): array
    {
        [$width, $height] = [([] === $rows ? 0.0 : \max(\array_column($rows, 0))) + (2 * $margin), \array_sum(\array_column($rows, 1))];
        $aspect = $width > 0 ? \min(0.8, \max(0.2, $height / $width)) : 0.8;
        // Each line's half width, and its top and bottom as measured, from the first line's top.
        $spans = [];
        $top = 0.0;
        foreach ($rows as [$rowWidth, $rowHeight, $above]) {
            $spans[] = [$rowWidth / 2, $top - $above, $top + $rowHeight - $above];
            $top += $rowHeight;
        }
        if ($margin > 0) {
            $spans[] = [$width / 2, 0.0, $height];
        }
        // The lines centred, the circle's centre on their middle line: where, down it, the farthest corner is nearest.
        $farthest = static function (float $centre) use ($spans, $aspect): float {
            $far = 0.0;
            foreach ($spans as [$half, $from, $to]) {
                $far = \max($far, $half ** 2 + (\max(\abs($from - $centre), \abs($to - $centre)) / $aspect) ** 2);
            }

            return $far;
        };
        [$low, $high] = [[] === $spans ? 0.0 : \min(\array_column($spans, 1)), [] === $spans ? 0.0 : \max(\array_column($spans, 2))];
        for ($step = 0; $step < 100; ++$step) {
            [$one, $two] = [$low + (($high - $low) / 3), $high - (($high - $low) / 3)];
            [$low, $high] = $farthest($one) < $farthest($two) ? [$low, $two] : [$one, $high];
        }
        $centre = ($low + $high) / 2;
        $radius = \sqrt($farthest($centre));

        return [(2 * $radius) + 6, (2 * $radius * $aspect) + 6, ($radius * $aspect) + 3 - $centre - 2];
    }

    /** @return list<Mark> the stereotypes above the text, both centred within a width */
    private function label(float $x, float $y, float $width): array
    {
        if (null === $this->stereotypes) {
            return $this->name($x, $y, $width);
        }

        return [...$this->stereotypes->marks($x, $y, $width), ...$this->name($x, $y + $this->stereotypes->size()[1], $width)];
    }

    /** @return list<Mark> the text alone, centred within a width */
    private function name(float $x, float $y, float $width): array
    {
        if ($this->flushLeft) {
            $textWidth = $this->text->size()[0];

            return $this->text->marks($x + (($width - $textWidth) / 2), $y, $textWidth, 'left');
        }

        return $this->text->marks($x, $y, $width);
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

    /**
     * A business use case's slash, as PlantUML draws it: across its ellipse shrunk by a hundredth,
     * from 20° above its right to where the ellipse turned an eighth of a turn meets the same
     * upright through that point again.
     */
    private static function across(float $x, float $y, float $width, float $height, string $line, float $stroke): Mark
    {
        [$a, $b, $theta] = [$width / 2, $height / 2, \deg2rad(20)];
        $z = (($a * \cos($theta)) - ($b * \sin($theta))) * \M_SQRT1_2;
        [$ra, $rb] = [$a * \M_SQRT1_2, $b * \M_SQRT1_2];
        $other = \acos(((2 * $ra * $z) / ($ra ** 2 + $rb ** 2)) - \cos($theta));
        [$w, $h] = [$width * 0.99, $height * 0.99];
        $at = static fn (float $angle): array => [$x + ($w / 2) + (($w / 2) * \cos($angle)), $y + ($h / 2) + (($h / 2) * \sin($angle))];

        return new Mark(MarkKind::Polyline, [$at(-$theta), $at($other)], stroke: $line, strokeWidth: $stroke);
    }

    /**
     * An actor's head and its body under it, as PlantUML's `ActorAwesome` and `ActorHollow` draw
     * them: the body a path from the middle of its top, filled, that many below the head.
     *
     * @param array{float, string} $body how far below the head the body starts, and its path
     * @return list<Mark>
     */
    private static function figure(float $x, float $y, float $radius, array $body, string $line, string $back, float $stroke): array
    {
        $top = $y + (2 * $radius) + $body[0];
        $outline = \array_map(static fn (array $at): array => [$x + $at[0], $top + $at[1]], SvgPath::outlines($body[1])[0]);

        return [StateBox::disc($x, $y + $radius, $radius, $line, $back), new Mark(MarkKind::Polygon, $outline, stroke: $line, fill: $back, strokeWidth: $stroke, outline: Outline::Path)];
    }

    /** A business actor's head crossed by a chord, 21/64 of a half turn either side of its lower right, as PlantUML draws it. */
    private static function chord(float $x, float $y, string $line, float $stroke): Mark
    {
        [$turn, $y] = [(21 * \M_PI) / 64, $y + 8.5];
        $at = static fn (float $angle): array => [$x + (8 * \cos($angle)), $y + (8 * \sin($angle))];

        return new Mark(MarkKind::Polyline, [$at((\M_PI / 4) + $turn), $at((\M_PI / 4) - $turn)], stroke: $line, strokeWidth: $stroke);
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
