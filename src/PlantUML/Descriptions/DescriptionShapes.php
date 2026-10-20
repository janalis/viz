<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/** The outlines of PlantUML's boxed symbols, in points: each its own shape around a box. */
final readonly class DescriptionShapes
{
    private function __construct(
        private string $line,
        private string $back,
        private float $stroke,
    ) {}

    /** @return list<Mark> */
    public static function outline(string $symbol, float $x, float $y, float $width, float $height, string $line, string $back, float $stroke, float $round): array
    {
        $pen = new self($line, $back, $stroke);
        // As PlantUML, a node and a database take 10 more room past a bottom corner, drawing nothing there.
        $room = match ($symbol) {
            'node' => [self::room($x, $y + $height)],
            'database' => [self::room($x + $width, $y + $height)],
            default => [],
        };

        return [
            ...$room,
            ...match ($symbol) {
                'component', 'node', 'frame', 'stack', 'collections' => $pen->framed($symbol, $x, $y, $width, $height, $round),
                'folder', 'file', 'artifact', 'package' => $pen->tabbed($symbol, $x, $y, $width, $height),
                'hexagon', 'action', 'process' => [$pen->polygon(self::pointed($symbol, $x, $y, $width, $height))],
                'database' => self::cylinder($x, $y, $width, $height, $line, $back, $stroke),
                'queue' => self::cylinder($x, $y, $width, $height, $line, $back, $stroke, across: 1),
                'cloud' => [$pen->polygon(self::cloud($x, $y, $width, $height))],
                'storage' => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, 50.0)],
                'person' => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, 20.0)],
                'card' => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, 0.0)],
                'label' => [],
                default => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, $round)],
            },
        ];
    }

    /** Room 10 by 10 from a point, drawn with no line, which a diagram's extent takes in. */
    private static function room(float $x, float $y): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x, $y], [$x + 10, $y + 10]], stroke: 'none', strokeWidth: 0.0);
    }

    /** @return list<Mark> a box with its symbol's own marks: a component's icon, a node's depth, a frame's tab, a stack's sides, a collection's copy */
    private function framed(string $symbol, float $x, float $y, float $width, float $height, float $round): array
    {
        $right = $x + $width;
        $bottom = $y + $height;

        return match ($symbol) {
            'component' => [
                ClassBox::rectangle($x, $y, $width, $height, $this->line, $this->back, $this->stroke, $round),
                $this->polygon(ClassBox::corners($right - 20, $y + 5, 15, 10)),
                $this->polygon(ClassBox::corners($right - 22, $y + 7, 4, 2)),
                $this->polygon(ClassBox::corners($right - 22, $y + 11, 4, 2)),
            ],
            'node' => [
                $this->polygon([[$x, $y + 10], [$x + 10, $y], [$right, $y], [$right, $bottom - 10], [$right - 10, $bottom], [$x, $bottom]]),
                $this->polyline([[$x, $y + 10], [$right - 10, $y + 10], [$right, $y]]),
                $this->polyline([[$right - 10, $y + 10], [$right - 10, $bottom]]),
            ],
            'frame' => self::frame($x, $y, $width, $height, $this->line, $this->back, $this->stroke, 0.0, 0.0),
            'stack' => [
                $this->polygon(ClassBox::corners($x + 10, $y, $width - 20, $height)),
                $this->polyline([[$x, $y], [$x + 10, $y + 5], [$x + 10, $bottom - 5], [$x, $bottom]]),
                $this->polyline([[$right, $y], [$right - 10, $y + 5], [$right - 10, $bottom - 5], [$right, $bottom]]),
            ],
            default => [$this->polygon(ClassBox::corners($x + 4, $y, $width - 4, $height - 4)), $this->polygon(ClassBox::corners($x, $y + 4, $width - 4, $height - 4))],
        };
    }

    /**
     * A frame as PlantUML draws one: a box with a tab at its top left, the tab as wide as its
     * title and 10 more, 3 taller, its corner cut 10 — or with no title, a third of the box wide,
     * 12 tall, its corner cut 7.
     *
     * @return list<Mark>
     */
    public static function frame(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke, float $titleWidth, float $titleHeight): array
    {
        [$tab, $tall, $corner] = 0.0 === $titleWidth ? [$width / 3, 12.0, 7.0] : [$titleWidth + 10, $titleHeight + 3, 10.0];
        $pen = new self($line, $back, $stroke);

        return [$pen->polygon(ClassBox::corners($x, $y, $width, $height)), $pen->polyline([[$x + $tab, $y], [$x + $tab, $y + $tall - $corner], [$x + $tab - $corner, $y + $tall], [$x, $y + $tall]])];
    }

    /** @return list<Mark> a box with a tab or a folded corner: a folder, a package, a file, an artifact */
    private function tabbed(string $symbol, float $x, float $y, float $width, float $height): array
    {
        $right = $x + $width;
        $bottom = $y + $height;
        $tab = \min('folder' === $symbol ? 50.0 : 40.0, $width / 3);

        return match ($symbol) {
            'folder' => [
                $this->polygon([[$x, $y], [$x + $tab, $y], [$x + $tab + 5, $y + 8], [$right, $y + 8], [$right, $bottom], [$x, $bottom]]),
                $this->polyline([[$x, $y + 8], [$x + $tab + 5, $y + 8]]),
            ],
            'package' => [$this->polygon([[$x, $y], [$x + $tab, $y], [$x + $tab, $y + 8], [$right, $y + 8], [$right, $bottom], [$x, $bottom]])],
            default => [
                $this->polygon([[$x, $y], [$right - 10, $y], [$right, $y + 10], [$right, $bottom], [$x, $bottom]]),
                $this->polyline([[$right - 10, $y], [$right - 10, $y + 10], [$right, $y + 10]]),
            ],
        };
    }

    /** @return list<array{float, float}> a hexagon, an action's arrow, a process's chevron */
    private static function pointed(string $symbol, float $x, float $y, float $width, float $height): array
    {
        $right = $x + $width;
        $bottom = $y + $height;
        $middle = $y + ($height / 2);

        return match ($symbol) {
            'hexagon' => [[$x + 10, $y], [$right - 10, $y], [$right, $middle], [$right - 10, $bottom], [$x + 10, $bottom], [$x, $middle]],
            'action' => [[$x, $y], [$right - 10, $y], [$right, $middle], [$right - 10, $bottom], [$x, $bottom]],
            default => [[$x, $y], [$right - 10, $y], [$right, $middle], [$right - 10, $bottom], [$x, $bottom], [$x + 10, $middle]],
        };
    }

    /** @param list<array{float, float}> $points */
    private function polygon(array $points): Mark
    {
        return new Mark(MarkKind::Polygon, $points, stroke: $this->line, fill: $this->back, strokeWidth: $this->stroke);
    }

    /** @param list<array{float, float}> $points */
    private function polyline(array $points): Mark
    {
        return new Mark(MarkKind::Polyline, $points, stroke: $this->line, strokeWidth: $this->stroke);
    }

    /** @return list<Mark> a cylinder standing up, or lying across for a queue */
    private static function cylinder(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke, int $across = 0): array
    {
        $cap = 10.0;
        $side = [];
        $rim = [];
        for ($step = 0; $step <= 16; ++$step) {
            $angle = (\M_PI * $step) / 16;
            if (0 === $across) {
                $side[] = [$x + ($width / 2) - (($width / 2) * \cos($angle)), $y + ($cap / 2) + (($cap / 2) * \sin($angle))];
                $rim[] = [$x + ($width / 2) - (($width / 2) * \cos($angle)), $y + ($cap / 2) - (($cap / 2) * \sin($angle))];
                continue;
            }
            $side[] = [$x + $width - ($cap / 2) - (($cap / 2) * \sin($angle)), $y + ($height / 2) - (($height / 2) * \cos($angle))];
            $rim[] = [$x + $width - ($cap / 2) + (($cap / 2) * \sin($angle)), $y + ($height / 2) - (($height / 2) * \cos($angle))];
        }
        $body = 0 === $across
            ? [
                ...$rim,
                [$x + $width, $y + $height - ($cap / 2)],
                ...\array_map(static fn (array $point): array => [$point[0], $point[1] + $height - $cap], \array_reverse($side)),
                [$x, $y + ($cap / 2)],
            ]
            : [
                [$x + ($cap / 2), $y],
                ...$rim,
                [$x + ($cap / 2), $y + $height],
                ...\array_map(static fn (array $point): array => [$x + $cap - ($point[0] - $x - $width + $cap), $point[1]], \array_reverse($rim)),
            ];

        return [new Mark(MarkKind::Polygon, $body, stroke: $line, fill: $back, strokeWidth: $stroke), new Mark(MarkKind::Polyline, $side, stroke: $line, strokeWidth: $stroke)];
    }

    /** @return list<array{float, float}> a cloud: bumps around a box */
    private static function cloud(float $x, float $y, float $width, float $height): array
    {
        $points = [];
        for ($step = 0; $step < 72; ++$step) {
            $angle = (2 * \M_PI * $step) / 72;
            $bump = 1 + (0.06 * \abs(\sin($angle * 6)));
            $points[] = [$x + ($width / 2) + ((($width / 2) * $bump * \cos($angle)) / 1.06), $y + ($height / 2) + ((($height / 2) * $bump * \sin($angle)) / 1.06)];
        }

        return $points;
    }
}
