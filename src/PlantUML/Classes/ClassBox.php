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
    private const float RADIUS = 11.0;
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
        $generic = null === $entity->generic ? null : PlantUmlText::styled([$entity->generic], $sheet->style(['root', 'element', 'classDiagram', 'class', 'stereotype']), FontFace::Italic);
        if (\in_array($entity->type, ['circle', 'diamond'], strict: true)) {
            // As svek writes them, and dot sizes them: a circle in a cell 18 across, its name's height above and below and as wide as its name, in whole points, 4 around, 54 wide at least; a diamond 24 across, nameless.
            $plain = PlantUmlText::styled($entity->display, $headerStyle);
            [$nameWidth, $nameHeight] = $plain->size();
            [$width, $height] = 'circle' === $entity->type
                ? [\max(54.0, 18 + (2 * \round(\max(0.5, ($nameWidth - 18) / 2))) + 8), (2 * \round($nameHeight)) + 18 + 8]
                : [24.0, 24.0];

            return new self($entity, $style, $sheet, $plain, null, null, 0.0, [], false, $width, $height, 0.0, $letter);
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
        foreach ($compartments as [$lines]) {
            $text = new PlantUmlText(\array_map(self::text(...), $lines), $style->fontSize(), sprites: $style->sprites);
            [$textWidth] = $text->size();
            $width = \max($width, $textWidth + 12 + ($iconSize > 0 && $visibility ? self::RADIUS + 3 : 0.0));
            $height += 8 + (\count($lines) * $line);
        }

        return new self($entity, $style, $sheet, $name, $stereotypes, $generic, $circle ? $diameter : 0.0, $compartments, $iconSize > 0 && $visibility, $width, $height, $header, $letter);
    }

    /** @return list<Mark> the box with its top left at a point */
    public function marks(float $x, float $y): array
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
        $marks = Shadow::cast([self::rectangle($x, $y, $this->width, $this->height, $line, $back, $stroke, $this->style->number('RoundCorner'))], $this->style->number('Shadowing'));
        \array_push($marks, ...$this->headerMarks($x, $y));
        $top = $y + $this->header;
        foreach ($this->compartments as [$lines, $separator, $title]) {
            \array_push($marks, ...self::separator($x, $top, $this->width, $separator, $title, $line, $stroke, $this->style));
            $text = new PlantUmlText(
                \array_map(self::text(...), $lines),
                $this->style->fontSize(),
                color: $this->style->color('FontColor', '#000000'),
                sprites: $this->style->sprites,
                background: $back,
            );
            $left = $x + 6 + ($this->icons ? self::RADIUS + 3 : 0.0);
            foreach ($lines as $index => $member) {
                $rowTop = $top + 4 + ($index * $text->line());
                \array_push($marks, ...$this->member($member, $left, $rowTop, $text));
            }
            $top += 8 + (\count($lines) * $text->line());
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
        [$stereoWidth, $stereoHeight] = null === $stereotypes ? [0.0, 0.0] : $stereotypes->size();
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
        [$stereoWidth, $stereoHeight] = null === $this->stereotypes ? [0.0, 0.0] : $this->stereotypes->size();
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
        if (null !== $this->generic) {
            [$width, $height] = $this->generic->size();
            $boxLeft = $x + $this->width - $width - 2;
            $marks[] = new Mark(MarkKind::Polygon, self::corners($boxLeft - 2, $y - 4, $width + 4, $height + 2), stroke: '#181818', fill: '#ffffff', dashed: true, strokeWidth: 0.5);
            \array_push($marks, ...$this->generic->marks($boxLeft, $y - 3, $width));
        }

        return $marks;
    }

    /** @return list<Mark> the circled character of the classifier's kind */
    private function spot(float $x, float $y): array
    {
        [$character, $name] = self::SPOTS[$this->entity->type] ?? ['C', 'spotClass'];
        $style = $this->sheet->style(['root', 'element', 'spot', $name]);
        $circle = [];
        for ($step = 0; $step < 24; ++$step) {
            $angle = (2 * \M_PI * $step) / 24;
            $circle[] = [$x + (($this->circle / 2) * \cos($angle)), $y + (($this->circle / 2) * \sin($angle))];
        }
        $font = $this->letter;

        return [
            new Mark(MarkKind::Polygon, $circle, stroke: $style->color('LineColor', '#181818'), fill: $style->color('BackGroundColor', '#add1b2'), strokeWidth: 1.0),
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

    /** @return list<Mark> a member: its visibility's icon, then its text, underlined when static */
    private function member(string $member, float $left, float $top, PlantUmlText $text): array
    {
        $marks = [];
        $visibility = \ltrim($member)[0] ?? '';
        if ($this->icons && \array_key_exists($visibility, self::ICONS)) {
            \array_push($marks, ...self::icon(self::ICONS[$visibility], ClassBody::method($member) ? 2 : 1, $left - self::RADIUS + 1, $top + ($text->line() / 2)));
        }
        $row = new PlantUmlText(
            [self::text($member)],
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
    private static function separator(float $x, float $y, float $width, string $kind, string $title, string $color, float $stroke, Style $style): array
    {
        $marks = match ($kind) {
            '..' => [self::rule($x, $y, $width, $color, $stroke, [1.0, 2.0])],
            '==' => [self::rule($x, $y - 1, $width, $color, $stroke), self::rule($x, $y + 1, $width, $color, $stroke)],
            '__' => [self::rule($x, $y, $width, $color, $stroke * 3)],
            default => [self::rule($x, $y, $width, $color, $stroke)],
        };
        if ('' === $title) {
            return $marks;
        }
        $text = new PlantUmlText([$title], $style->fontSize(), color: $style->color('FontColor', '#000000'));
        [$textWidth, $textHeight] = $text->size();
        $marks[] = self::rectangle($x + (($width - $textWidth) / 2) - 2, $y - ($textHeight / 2), $textWidth + 4, $textHeight, 'none', $style->color('BackGroundColor', '#f1f1f1'), 0.0, 0.0);

        return [...$marks, ...$text->marks($x, $y - ($textHeight / 2), $width)];
    }

    /** @param ?list<float> $dashes */
    private static function rule(float $x, float $y, float $width, string $color, float $stroke, ?array $dashes = null): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x, $y], [$x + $width, $y]], stroke: $color, dashed: null !== $dashes, strokeWidth: $stroke, dashes: $dashes);
    }

    /** A member's text without its visibility character and modifiers. */
    private static function text(string $member): string
    {
        $member = \trim((string) \preg_replace('/\{(static|classifier|abstract|field|method)\}/i', replacement: '', subject: $member));

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
