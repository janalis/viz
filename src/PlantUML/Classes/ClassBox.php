<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Text\FontFace;

/**
 * A classifier drawn as PlantUML draws it, in points: a header with the circled character of
 * its kind, its stereotypes above its name — italic for an abstract class or an interface —
 * and its type parameters in a dashed box over the top right corner; then its compartments,
 * each member after the icon of its visibility.
 */
final readonly class ClassBox
{
    /** A circle entity's radius, as PlantUML draws one. */
    public const float DOT = 8.0;
    /**
     * Each visibility's icon: its shape and its colour for a field, then for a method, which is filled.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array ICONS = [
        '-' => ['square', '#c82930', '#f24d5c'],
        '#' => ['diamond', '#b38d22', '#ffff44'],
        '~' => ['triangle', '#1963a0', '#4177af'],
        '+' => ['circle', '#038048', '#84be84'],
    ];
    private const array SPOTS = [
        'annotation' => ['@', 'spotAnnotation'],
        'abstract' => ['A', 'spotAbstractClass'],
        'interface' => ['I', 'spotInterface'],
        'enum' => ['E', 'spotEnum'],
        'entity' => ['E', 'spotEntity'],
        'protocol' => ['P', 'spotProtocol'],
        'struct' => ['S', 'spotStruct'],
        'exception' => ['X', 'spotException'],
        'metaclass' => ['M', 'spotMetaClass'],
        'stereotype' => ['S', 'spotStereotype'],
        'dataclass' => ['D', 'spotDataClass'],
        'record' => ['R', 'spotRecord'],
    ];

    /**
     * @param list<array{list<string>, string, string}> $compartments
     */
    private function __construct(
        private ClassEntity $entity,
        private Style $style,
        private StyleSheet $sheet,
        private PlantUmlText $name,
        private ?PlantUmlText $stereotypes,
        private ?PlantUmlText $generic,
        /** The circled character's diameter, 0 when hidden. */
        private float $circle,
        private array $compartments,
        private bool $icons,
        public float $width,
        public float $height,
        public float $header,
        /** The circled character's font size. */
        private float $letter = 17.0,
        /** Whether a member's visibility is written as text, as PlantUML writes it with no icons. */
        private bool $written = false,
    ) {}

    /**
     * @param float $iconSize the visibility icons' size, `skinparam classAttributeIconSize`; 0 writes visibility as text
     * @param float $letter the circled character's font size, `skinparam circledCharacterFontSize`, which sets its circle's radius: a third of it and 17, to the nearest point, as measured on PlantUML's output
     */
    public static function of(ClassEntity $entity, StyleSheet $sheet, ClassPortions $portions, float $iconSize = 10.0, float $letter = 17.0): self
    {
        $diameter = 2 * \round(($letter + 17) / 3);
        $style = $sheet->style(['root', 'element', 'classDiagram', 'class'], $entity->stereotypes);
        $italic = \in_array($entity->type, ['abstract', 'interface'], strict: true);
        $headerStyle = $sheet->style(['root', 'element', 'classDiagram', 'class', 'header'], $entity->stereotypes);
        $name = PlantUmlText::styled($entity->display, $headerStyle, $italic ? FontFace::Italic : null);
        $labels = $portions->shown($entity, 'stereotype') ? $entity->stereotypes : [];
        $stereotypes = [] === $labels
            ? null
            : PlantUmlText::styled(
                \array_map(static fn (string $label): string => '«'.$label.'»', $labels),
                self::stereotyped($sheet->style(['root', 'element', 'classDiagram', 'class', 'stereotype'], $entity->stereotypes)),
                FontFace::Italic,
            );
        $generic = null === $entity->generic
            ? null
            : PlantUmlText::styled([$entity->generic], self::stereotyped($sheet->style(['root', 'element', 'classDiagram', 'class', 'stereotype'])), FontFace::Italic);
        if (\in_array($entity->type, ['circle', 'diamond'], strict: true)) {
            // As svek writes them, and dot sizes them: a circle in a cell 18 across, its name's height above and below and as wide as its name, in whole points, 4 around, 54 wide at least; a diamond 24 across, nameless.
            $plain = PlantUmlText::styled($entity->display, $headerStyle);
            [$nameWidth, $nameHeight] = $plain->size();
            [$width, $height] = 'circle' === $entity->type
                ? [\max(54.0, 18 + (2 * \round(\max(0.5, ($nameWidth - 18) / 2))) + 8), (2 * \round($nameHeight)) + 18 + 8]
                : [24.0, 24.0];

            return new self($entity, $style, $sheet, $plain, null, null, 0.0, [], false, $width, $height, 0.0, $letter);
        }
        if (\in_array($entity->type, ['object', 'map'], strict: true)) {
            return self::object($entity, $sheet, $letter);
        }
        $circle = $portions->shown($entity, 'circle');
        $compartments = ClassBody::of($entity, $portions)->compartments;
        $visibility = false;
        foreach ($compartments as [$lines]) {
            foreach ($lines as $member) {
                $visibility = $visibility || \array_key_exists(\ltrim($member)[0] ?? '', self::ICONS);
            }
        }
        [$headerWidth, $header] = self::header($name, $stereotypes, $generic, $circle ? $diameter : 0.0);
        $width = $headerWidth;
        $height = $header;
        $line = $name->line();
        foreach ($compartments as [$lines, , $title]) {
            $text = new PlantUmlText(\array_map($iconSize > 0 ? self::text(...) : self::written(...), $lines), $style->fontSize(), sprites: $style->sprites);
            [$textWidth] = $text->size();
            [$titleWidth, $titleHeight] = self::title($title, $style)?->size() ?? [0.0, 0.0];
            // As PlantUML, a compartment under a titled line 8 wider than its title at least, and half its title's height above and below the line.
            $width = \max($width, $textWidth + 12 + ($iconSize > 0 && $visibility ? self::room($letter) : 0.0), '' === $title ? 0.0 : $titleWidth + 8);
            $height += ('' === $title ? 8 : $titleHeight + 4) + (\count($lines) * $line);
        }

        return new self(
            $entity,
            $style,
            $sheet,
            $name,
            $stereotypes,
            $generic,
            $circle ? $diameter : 0.0,
            $compartments,
            $iconSize > 0 && $visibility,
            $width,
            $height,
            $header,
            $letter,
            $iconSize <= 0,
        );
    }

    /**
     * An object, as PlantUML's `EntityImageObject` sizes it: its name centred 2 below its top
     * and 7 in from its sides at least, its fields below as a class's, or an empty row 16 tall.
     */
    private static function object(ClassEntity $entity, StyleSheet $sheet, float $letter): self
    {
        $style = $sheet->style(['root', 'element', 'objectDiagram', $entity->type], $entity->stereotypes);
        $name = PlantUmlText::styled($entity->display, $sheet->style(['root', 'element', 'objectDiagram', $entity->type, 'header'], $entity->stereotypes));
        [$nameWidth, $nameHeight] = $name->size();
        if ('map' === $entity->type) {
            [$keys, $values] = self::entries($entity, $style);
            $rows = \array_sum(\array_map(static fn (PlantUmlText $key, PlantUmlText $value): float => \max($key->size()[1], $value->size()[1]) + 4, $keys, $values));
            $columns = self::column($keys) + self::column($values);

            return new self($entity, $style, $sheet, $name, null, null, 0.0, [], false, \max($nameWidth + 14, $columns), $nameHeight + 4 + $rows, $nameHeight + 4, $letter);
        }
        $fields = new PlantUmlText(\array_map(self::text(...), $entity->body), $style->fontSize(), sprites: $style->sprites);
        $header = $nameHeight + 4;
        $height = $header + ([] === $entity->body ? 16.0 : 8 + (\count($entity->body) * $fields->line()));
        $width = \max($nameWidth + 14, [] === $entity->body ? 0.0 : $fields->size()[0] + 12);

        return new self($entity, $style, $sheet, $name, null, null, 0.0, [[$entity->body, '', '']], false, $width, $height, $header, $letter);
    }

    /**
     * A map's entries, each key's and value's text, as PlantUML's `TextBlockMap` takes them: a
     * key without its visibility character.
     *
     * @return array{list<PlantUmlText>, list<PlantUmlText>}
     */
    private static function entries(ClassEntity $entity, Style $style): array
    {
        [$keys, $values] = [[], []];
        foreach ($entity->body as $entry) {
            [$key, $value] = \array_map(\trim(...), \explode('=>', $entry, limit: 2));
            $key = \array_key_exists($key[0] ?? '', self::ICONS) ? \substr($key, offset: 1) : $key;
            $keys[] = PlantUmlText::styled(ClassReader::split($key), $style);
            $values[] = PlantUmlText::styled(ClassReader::split($value), $style);
        }

        return [$keys, $values];
    }

    /**
     * How tall svek writes a map's rows, as its table of ports: a row for each entry, as tall as
     * its entry in whole points down, each after a row for what lies before it, in whole points
     * down, from where the last ended; then a row for what is left.
     */
    public function rows(): float
    {
        [$keys, $values] = self::entries($this->entity, $this->style);
        [$sum, $top] = [0, $this->header];
        foreach ($keys as $index => $key) {
            $height = \max($key->size()[1], $values[$index]->size()[1]) + 4;
            $sum += \max(0, (int) ($top - $sum)) + (int) $height;
            $top += $height;
        }

        return $sum + \max(0, (int) ($this->height - $sum));
    }

    /**
     * A map's column's width: its widest text and 5 either side.
     *
     * @param list<PlantUmlText> $texts
     */
    private static function column(array $texts): float
    {
        return [] === $texts ? 0.0 : \max(\array_map(static fn (PlantUmlText $text): float => $text->size()[0], $texts)) + 10;
    }

    /**
     * A map's entries as PlantUML's `TextBlockMap` draws them: each row under a line across the
     * map, its key centred in the first column, its value 5 into the second, after a line.
     *
     * @return list<Mark>
     */
    private function mapMarks(float $x, float $y): array
    {
        $line = $this->style->color('LineColor', '#181818');
        [$keys, $values] = self::entries($this->entity, $this->style);
        $first = self::column($keys);
        $marks = [];
        $top = $y + $this->header;
        foreach ($keys as $index => $key) {
            $value = $values[$index];
            $height = \max($key->size()[1], $value->size()[1]) + 4;
            $marks[] = new Mark(MarkKind::Polyline, [[$x, $top], [$x + $this->width, $top]], stroke: $line, strokeWidth: 1.0);
            \array_push($marks, ...$key->marks($x, $top + 2, $first), ...$value->marks($x + $first + 5, $top + 2, $value->size()[0]));
            $marks[] = new Mark(MarkKind::Polyline, [[$x + $first, $top], [$x + $first, $top + $height]], stroke: $line, strokeWidth: 1.0);
            $top += $height;
        }

        return $marks;
    }

    /** @return list<Mark> the box with its top left at a point */
    public function marks(float $x, float $y): array
    {
        return [...$this->bare($x, $y), ...$this->genericMarks($x, $y)];
    }

    /** @return list<Mark> the box with its top left at a point, without its type parameters */
    public function bare(float $x, float $y): array
    {
        $line = $this->style->color('LineColor', '#181818');
        $back = ClassColors::back($this->entity->color) ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $stroke = $this->style->number('LineThickness', 0.5);
        if ('circle' === $this->entity->type) {
            [$cx, $cy] = [$x + ($this->width / 2), $y + ($this->height / 2)];

            return [DescriptionBox::ellipse($cx - self::DOT, $cy - self::DOT, 2 * self::DOT, 2 * self::DOT, $line, $back, $stroke), ...$this->name->marks($x, $cy + 17, $this->width)];
        }
        if ('diamond' === $this->entity->type) {
            [$cx, $cy] = [$x + 12, $y + 12];

            return [new Mark(MarkKind::Polygon, [[$cx, $y], [$x + 24, $cy], [$cx, $y + 24], [$x, $cy]], stroke: $line, fill: $back, strokeWidth: $stroke, outline: Outline::Path)];
        }
        $marks = Shadow::cast([self::rectangle($x, $y, $this->width, $this->height, $line, $back, $stroke, $this->style->number('RoundCorner'))
            ->with(['outline' => \in_array($this->entity->type, ['object', 'map'], strict: true) ? Outline::Box : Outline::Block])], $this->style->number('Shadowing'));
        \array_push($marks, ...\in_array($this->entity->type, ['object', 'map'], strict: true) ? $this->name->marks($x, $y + 2, $this->width) : $this->headerMarks($x, $y));
        if ('map' === $this->entity->type) {
            return [...$marks, ...$this->mapMarks($x, $y)];
        }
        if ('object' === $this->entity->type && [] !== $this->entity->body) {
            // As PlantUML draws an object's fields: the line above them as wide as they are, 6 either side, cut to its outline, which its limit finder takes uncut.
            [$fields] = new PlantUmlText(\array_map(self::text(...), $this->entity->body), $this->style->fontSize(), sprites: $this->style->sprites)->size();
            $marks[] = new Mark(MarkKind::Polygon, [[$x, $y + $this->header], [$x + $fields + 12, $y + $this->header]], stroke: 'none', fill: 'none', strokeWidth: 0.0, outline: Outline::Path);
        }
        $top = $y + $this->header;
        foreach ($this->compartments as [$lines, $separator, $title]) {
            \array_push($marks, ...self::separator($x, $top, $this->width, $separator, $title, $line, $stroke, $this->style));
            $text = new PlantUmlText(
                \array_map($this->shown(...), $lines),
                $this->style->fontSize(),
                color: $this->style->color('FontColor', '#000000'),
                sprites: $this->style->sprites,
                background: $back,
            );
            $left = $x + 6 + ($this->icons ? self::room($this->letter) : 0.0);
            $above = '' === $title ? 4.0 : self::title($title, $this->style)?->size()[1] ?? 0.0;
            foreach ($lines as $index => $member) {
                $rowTop = $top + $above + ($index * $text->line());
                \array_push($marks, ...$this->member($member, $left, $rowTop, $text));
            }
            $top += $above + 4 + (\count($lines) * $text->line());
        }

        return $marks;
    }

    /** A class's stereotype style at 12 points, as PlantUML sets it whatever its styles' font size. */
    private static function stereotyped(Style $style): Style
    {
        return new Style(['FontSize' => '12'] + $style->values, $style->sprites);
    }

    /** @return array{float, float} the header's width and height, as PlantUML's header layout gives them */
    private static function header(PlantUmlText $name, ?PlantUmlText $stereotypes, ?PlantUmlText $generic, float $circle): array
    {
        [$nameWidth, $nameHeight] = $name->size();
        // As PlantUML, its stereotypes 1 wider either side than their text.
        [$stereoWidth, $stereoHeight] = null === $stereotypes ? [0.0, 0.0] : [$stereotypes->size()[0] + 2, $stereotypes->size()[1]];
        [$genericWidth, $genericHeight] = null === $generic ? [0.0, 0.0] : $generic->size();
        $circleWidth = $circle > 0 ? $circle + 4 : 0.0;
        $circleHeight = $circle > 0 ? $circle + 10 : 0.0;
        $genericWidth = null === $generic ? 0.0 : $genericWidth + 4;

        return [$circleWidth + \max($stereoWidth, $nameWidth + 6) + $genericWidth, \max($circleHeight, $stereoHeight + $nameHeight + 10, $genericHeight + 4)];
    }

    /** @return list<Mark> */
    private function headerMarks(float $x, float $y): array
    {
        [$nameWidth, $nameHeight] = $this->name->size();
        [$stereoWidth, $stereoHeight] = null === $this->stereotypes ? [0.0, 0.0] : [$this->stereotypes->size()[0] + 2, $this->stereotypes->size()[1]];
        $circleWidth = $this->circle > 0 ? $this->circle + 4 : 0.0;
        $middle = \max($stereoWidth, $nameWidth + 6);
        $genericWidth = null === $this->generic ? 0.0 : $this->generic->size()[0] + 4;
        $spare = \max(0.0, $this->width - $circleWidth - $middle - $genericWidth);
        $h2 = \min($circleWidth / 4, $spare * 0.1);
        $h1 = ($spare - $h2) / 2;
        $marks = [];
        if ($this->circle > 0) {
            \array_push($marks, ...$this->spot($x + $h1 + 4 + ($this->circle / 2), $y + ($this->header / 2)));
        }
        $textTop = $y + (($this->header - $stereoHeight - $nameHeight) / 2);
        $left = $x + $circleWidth + $h1 + $h2;
        if (null !== $this->stereotypes) {
            \array_push($marks, ...$this->stereotypes->marks($left, $textTop, $middle));
        }
        \array_push($marks, ...$this->name->marks($left, $textTop + $stereoHeight, $middle));

        return $marks;
    }

    /** @return list<Mark> its type parameters, if it has any and is drawn as a box */
    private function genericMarks(float $x, float $y): array
    {
        $marks = [];
        if (null !== $this->generic && !\in_array($this->entity->type, ['circle', 'diamond'], strict: true)) {
            // As PlantUML draws it: a box 2 bigger than its text, 3 past the class's top right corner, its text 1 in.
            [$width, $height] = $this->generic->size();
            $boxLeft = $x + $this->width + 3 - ($width + 2);
            // As PlantUML's limit finder is seen to take it: 1 bigger all round.
            $marks[] = new Mark(MarkKind::Polygon, self::corners($boxLeft - 1, $y - 4, $width + 4, $height + 4), stroke: 'none', fill: 'none', strokeWidth: 0.0, outline: Outline::Path);
            $marks[] = new Mark(
                MarkKind::Polygon,
                self::corners($boxLeft, $y - 3, $width + 2, $height + 2),
                stroke: '#181818',
                fill: '#ffffff',
                dashed: true,
                strokeWidth: 0.5,
                outline: Outline::Path,
            );
            \array_push($marks, ...$this->generic->marks($boxLeft + 1, $y - 2, $width));
        }

        return $marks;
    }

    /** @return list<Mark> the circled character of the classifier's kind */
    private function spot(float $x, float $y): array
    {
        [$character, $name] = self::SPOTS[$this->entity->type] ?? ['C', 'spotClass'];
        $style = $this->sheet->style(['root', 'element', 'spot', $name]);
        // As PlantUML, a stereotype's `(C, colour)` circles its own character, in its own colour.
        [$character, $back] = [$this->entity->spot[0] ?? $character, $this->entity->spot[1] ?? $style->color('BackGroundColor', '#add1b2')];
        $circle = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $circle[] = [$x + (($this->circle / 2) * \cos($angle)), $y + (($this->circle / 2) * \sin($angle))];
        }
        $font = $this->letter;

        return [
            new Mark(MarkKind::Polygon, $circle, stroke: $style->color('LineColor', '#181818'), fill: $back, strokeWidth: 1.0),
            new Mark(
                MarkKind::Text,
                [[$x, $y + ((0.35 * Units::line($font)) / 2)]],
                $character,
                stroke: $style->color('FontColor', '#000000'),
                font: FontFace::Bold,
                fontScale: Units::fontScale($font) / Units::POINT,
            ),
        ];
    }

    /**
     * The room a member's visibility icon keeps before its text: 3 more than the circled
     * character's radius, as measured on PlantUML's output, whether or not the circle is drawn.
     */
    private static function room(float $letter): float
    {
        return \round(($letter + 17) / 3) + 3;
    }

    /** @return list<Mark> a member: its visibility's icon, then its text, underlined when static */
    private function member(string $member, float $left, float $top, PlantUmlText $text): array
    {
        $marks = [];
        $visibility = \ltrim($member)[0] ?? '';
        if ($this->icons && \array_key_exists($visibility, self::ICONS)) {
            \array_push($marks, ...self::icon(self::ICONS[$visibility], ClassBody::method($member) ? 2 : 1, $left - self::room($this->letter) + 4, $top + ($text->line() / 2)));
        }
        $row = new PlantUmlText(
            [$this->shown($member)],
            $text->size,
            \str_contains(\strtolower($member), '{abstract}') ? FontFace::Italic : FontFace::Regular,
            $text->color,
            $text->sprites,
            $text->background,
        );
        \array_push($marks, ...$row->marks($left, $top, 0.0, 'left'));
        if (\str_contains(\strtolower($member), '{static}') || \str_contains(\strtolower($member), '{classifier}')) {
            [$width] = $row->size();
            $marks[] = new Mark(MarkKind::Polyline, [[$left, $top + ($text->line() * 0.85)], [$left + $width, $top + ($text->line() * 0.85)]], stroke: $text->color, strokeWidth: 0.5);
        }

        return $marks;
    }

    /**
     * A visibility's icon: outlined for a field, filled for a method.
     *
     * @param array{string, string, string} $icon its shape, and its colours for a field and for a method
     * @param int $kind 1 for a field, 2 for a method
     * @return list<Mark>
     */
    private static function icon(array $icon, int $kind, float $x, float $y): array
    {
        [$shape] = $icon;
        $color = $icon[$kind];
        $size = 3.5;
        $points = match ($shape) {
            'square' => self::corners($x - $size, $y - $size, 2 * $size, 2 * $size),
            'diamond' => [[$x, $y - $size - 1], [$x + $size + 1, $y], [$x, $y + $size + 1], [$x - $size - 1, $y]],
            'triangle' => [[$x, $y - $size - 1], [$x + $size + 1, $y + $size], [$x - $size - 1, $y + $size]],
            default => \array_map(static fn (int $step): array => [$x + ($size * \cos(($step * \M_PI) / 8)), $y + ($size * \sin(($step * \M_PI) / 8))], \range(start: 0, end: 15)),
        };

        return [new Mark(MarkKind::Polygon, $points, stroke: $color, fill: 2 === $kind ? $color : 'none', strokeWidth: 1.0)];
    }

    /** @return list<Mark> the line above a compartment, and its title */
    /**
     * A separator as PlantUML's horizontal line draws one, 1 in from the box's sides: `--` and
     * `==` a point thick, `==` twice, 2 apart, `..` dotted, `__` and the line under the header
     * as thick as the box's line; a titled one half its title's height lower, its two halves
     * either side of its title, the title half a point above its middle.
     *
     * @return list<Mark>
     */
    private static function separator(float $x, float $y, float $width, string $kind, string $title, string $color, float $stroke, Style $style): array
    {
        $text = self::title($title, $style);
        [$textWidth, $textHeight] = $text?->size() ?? [0.0, 0.0];
        $y += $textHeight / 2;
        [$start, $end] = [$x + 1, $x + $width - 1];
        $half = null === $text ? $end - $start : ($end - $start - $textWidth) / 2;
        $marks = [];
        foreach (null === $text ? [$start] : [$start, $end - $half] as $from) {
            \array_push($marks, ...match ($kind) {
                '..' => [self::rule($from, $y, $half, $color, 1.0, [1.0, 2.0])],
                '==' => [self::rule($from, $y, $half, $color, 1.0), self::rule($from, $y + 2, $half, $color, 1.0)],
                '--' => [self::rule($from, $y, $half, $color, 1.0)],
                default => [self::rule($from, $y, $half, $color, $stroke)],
            });
        }

        return null === $text ? $marks : [...$marks, ...$text->marks($start, $y - ($textHeight / 2) - 0.5, $end - $start)];
    }

    /** A separator's title, in the class's font; null for none. */
    private static function title(string $title, Style $style): ?PlantUmlText
    {
        return '' === $title ? null : new PlantUmlText([$title], $style->fontSize(), color: $style->color('FontColor', '#000000'));
    }

    /** @param ?list<float> $dashes */
    private static function rule(float $x, float $y, float $width, string $color, float $stroke, ?array $dashes = null): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x, $y], [$x + $width, $y]], stroke: $color, dashed: null !== $dashes, strokeWidth: $stroke, dashes: $dashes);
    }

    /** A member's text as shown: without its visibility character, unless written as text, as PlantUML writes it, the spaces after it dropped. */
    private function shown(string $member): string
    {
        return $this->written ? self::written($member) : self::text($member);
    }

    /** A member's text with its visibility character written before it, as PlantUML writes it with no icons: the spaces after the character dropped. */
    private static function written(string $member): string
    {
        $member = self::modified($member);

        return \array_key_exists($member[0] ?? '', self::ICONS) ? $member[0].\ltrim(\substr($member, offset: 1)) : $member;
    }

    /** A member's text without its modifiers. */
    private static function modified(string $member): string
    {
        return \trim((string) \preg_replace('/\{(static|classifier|abstract|field|method)\}/i', replacement: '', subject: $member));
    }

    /** A member's text without its visibility character and modifiers. */
    private static function text(string $member): string
    {
        $member = self::modified($member);

        return \array_key_exists($member[0] ?? '', self::ICONS) ? \ltrim(\substr($member, offset: 1)) : $member;
    }

    public static function rectangle(float $x, float $y, float $width, float $height, string $stroke, string $fill, float $strokeWidth, float $round): Mark
    {
        return new Mark(
            MarkKind::Polygon,
            $round > 0 ? self::rounded($x, $y, $width, $height, $round / 2) : self::corners($x, $y, $width, $height),
            stroke: $stroke,
            fill: $fill,
            strokeWidth: $strokeWidth,
        );
    }

    /** @return list<array{float, float}> */
    public static function corners(float $x, float $y, float $width, float $height): array
    {
        return [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]];
    }

    /** @return list<array{float, float}> a rectangle with its corners rounded to a radius */
    private static function rounded(float $x, float $y, float $width, float $height, float $radius): array
    {
        $radius = \min($radius, $width / 2, $height / 2);
        $points = [];
        foreach ([[$x + $width - $radius, $y + $radius, -90], [$x + $width - $radius, $y + $height - $radius, 0], [$x + $radius, $y + $height - $radius, 90], [$x + $radius, $y + $radius, 180]] as [
            $cx,
            $cy,
            $start,
        ]) {
            for ($step = 0; $step <= 4; ++$step) {
                $angle = \deg2rad($start + ($step * 22.5));
                $points[] = [$cx + ($radius * \cos($angle)), $cy + ($radius * \sin($angle))];
            }
        }

        return $points;
    }
}
