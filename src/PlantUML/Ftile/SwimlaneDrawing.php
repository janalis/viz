<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\Lane;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\Source\Unsupported;

/**
 * An activity diagram's swimlanes, as PlantUML's swimlanes draw them: the steps laid out once,
 * each swimlane as wide as what it holds — or `skinparam swimlaneWidth`, or its title — set
 * side by side between dividers 10 apart, its title centred over it, its background behind,
 * each step moved across with its swimlane and the arrows between swimlanes bent across.
 */
final readonly class SwimlaneDrawing
{
    private const float HALF = 5.0;

    /** @var list<string> the swimlanes' names, and the empty one PlantUML closes them with */
    private array $names;
    /** @var array<string, array{float, float}> what each swimlane holds, from left to right */
    private array $spans;
    /** @var array<string, TextBlock> */
    private array $titles;
    private float $min;
    /** Whether `skinparam swimlaneWidth` sets the swimlanes' width. */
    private bool $fixed;
    private Style $style;

    /**
     * @param non-empty-list<Lane> $lanes
     * @param array<string, array{float, float}> $spans
     */
    private function __construct(
        private array $lanes,
        array $spans,
        FtileStyles $styles,
        string $width,
    ) {
        $this->style = $styles->style([], 'swimlane');
        $titles = [];
        foreach ($lanes as $lane) {
            $titles[$lane->name] = new Label($lane->title, $this->style);
        }
        $this->titles = $titles;
        $this->names = [...\array_map(static fn (Lane $lane): string => $lane->name, $lanes), ''];
        $this->spans = $spans + \array_fill_keys($this->names, [0.0, 0.0]);
        $min = match (true) {
            'same' === \strtolower($width) => -1.0,
            \ctype_digit($width) => (float) $width,
            default => 0.0,
        };
        if (-1.0 === $min) {
            foreach ($lanes as $lane) {
                $min = \max($min, $this->widthOf($lane->name));
            }
        }
        $this->min = $min;
        $this->fixed = '' !== \trim($width);
    }

    /**
     * The shapes of the steps in their swimlanes, the swimlanes' backgrounds, dividers and titles.
     *
     * @param non-empty-list<Lane> $lanes
     * @return list<Shape>
     * @throws Unsupported for a step holding steps of another swimlane, or what stands outside any
     */
    public static function shapes(Ftile $root, array $lanes, FtileStyles $styles, Pen $arrow, string $width): array
    {
        $measure = Canvas::create(new LaneView());
        $root->draw($measure);
        $spans = [];
        foreach ($measure->flushLanes($arrow) as $lane => $shapes) {
            $limits = \array_map(static fn (Shape $shape): array => $shape->limits(), $shapes);
            $spans[$lane] = [\min(\array_column($limits, 0)), \max(\array_column($limits, 2))];
        }

        return new self($lanes, $spans, $styles, $width)->draw($root, $arrow);
    }

    /** @return list<Shape> */
    private function draw(Ftile $root, Pen $arrow): array
    {
        $titlesHeight = 0.0;
        foreach ($this->titles as $title) {
            $titlesHeight = \max($titlesHeight, $title->size()[1]);
        }
        $down = $titlesHeight > 0 ? $titlesHeight + 5 : 0.0;
        $height = $root->geometry()->height + $down;
        [$offsets, $dividers] = $this->offsets();
        $back = $this->style->color('BackGroundColor', 'transparent');
        $last = $offsets[''];
        // As PlantUML, the titles' background is drawn even when transparent, and takes its room: past the last divider by three quarters of the titles' descent, unless the swimlanes' width is set, as measured on its output.
        $past = $this->fixed ? 0.0 : 0.75 * 0.210_937_5 * $this->style->fontSize();
        $shapes = [self::rectangle(self::HALF, 0.0, $last - (2 * self::HALF) - 1 + $past, $titlesHeight, $back)];
        $lines = [];
        $pen = Pen::of($this->style, '#000000');
        foreach ($this->names as $index => $name) {
            [$x1, $x2] = $dividers[$index];
            $x = $offsets[$name] + $this->spans[$name][0];
            $color = PlantUmlColor::parse((string) $this->laneColor($name));
            if (null !== $color) {
                $shapes[] = self::rectangle($x - $x2, 0.0, $this->actual($name) + $x2 + $dividers[$index + 1][0], $height, $color);
            }
            // The room past the last divider reaches as far past it as the titles' background does.
            $lines[] = Shape::empty($x - $x1 - $x2, 0.0, $x1 + $x2 + ('' === $name ? $past : 0.0), 1.0);
            $lines[] = Shape::of(ShapeKind::Line, [$pen->line($x - $x2, 0.0, $x - $x2, $height)]);
        }
        $canvas = Canvas::create(new LaneView($offsets, measuring: false))->moved(0.0, $down);
        $root->draw($canvas);
        $titles = [];
        foreach ($this->titles as $name => $title) {
            foreach ($title->shapes(0.0, 0.0) as $text) {
                $titles[] = Shape::centered($text->moved($offsets[$name] + $this->spans[$name][0], 0.0), $offsets[$name] + $this->spans[$name][0], $this->widthOf($name));
            }
        }

        return [...$shapes, ...$lines, ...$canvas->flush($arrow), ...$titles];
    }

    /**
     * Each swimlane's move across, and each divider's room either side of its line.
     *
     * @return array{array<string, float>, list<array{float, float}>}
     */
    private function offsets(): array
    {
        $dividers = [];
        for ($i = 0; $i <= \count($this->names); ++$i) {
            $dividers[] = [$this->halfMissing($i), $this->halfMissing($i + 1)];
        }
        $offsets = [];
        $x = 0.0;
        foreach ($this->names as $index => $name) {
            $divider = $dividers[$index][0] + $dividers[$index][1];
            $offsets[$name] = $x + $divider - $this->spans[$name][0] + (($this->actual($name) - $this->widthOf($name)) / 2.0);
            $x += $this->actual($name) + $divider;
        }

        return [$offsets, $dividers];
    }

    /** Half the room a divider leaves either side: 5, more when the title before it is wider than its swimlane. */
    private function halfMissing(int $index): float
    {
        if (0 === $index || $index > \count($this->names)) {
            return self::HALF;
        }
        $name = $this->names[$index - 1];
        $width = $this->actual($name);
        $title = '' === $name ? 0.0 : $this->titles[$name]->size()[0];

        return $title <= $width ? self::HALF : \max(self::HALF, self::HALF + (($title - $width) / 2));
    }

    private function actual(string $name): float
    {
        return \max($this->min, $this->widthOf($name));
    }

    private function widthOf(string $name): float
    {
        return $this->spans[$name][1] - $this->spans[$name][0];
    }

    private function laneColor(string $name): ?string
    {
        foreach ($this->lanes as $lane) {
            if ($lane->name === $name) {
                return $lane->color;
            }
        }

        return null;
    }

    /** A rectangle compression passes over, filled unless transparent. */
    private static function rectangle(float $x, float $y, float $width, float $height, string $fill): Shape
    {
        $marks = 'transparent' === \strtolower($fill) ? [] : [new Pen($fill)->rectangle($x, $y, $width, $height, $fill)];

        return new Shape(ShapeKind::Rectangle, $marks, $x, $y, $x + $width, $y + $height, ignoreX: true, ignoreY: true);
    }
}
