<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\JavaRandom;

/** The outlines of PlantUML's boxed symbols, in points: each its own shape around a box. */
final readonly class DescriptionShapes
{
    private function __construct(
        private string $line,
        private string $back,
        private float $stroke,
    ) {}

    /**
     * @param array{float, float} $title the size of the title a folder's or a package's tab holds, a folder element's the 40 by 15 PlantUML keeps for one it does not show
     * @return list<Mark>
     */
    public static function outline(string $symbol, float $x, float $y, float $width, float $height, string $line, string $back, float $stroke, float $round, array $title = [40.0, 15.0]): array
    {
        $pen = new self($line, $back, $stroke);
        // As PlantUML, a node and a database take 10 more room past a bottom corner, drawing nothing there.
        $room = match ($symbol) {
            'node' => [self::room($x, $y + $height)],
            'database' => [self::room($x + $width, $y + $height)],
            default => [],
        };
        $outline = match ($symbol) {
            'component', 'component1', 'node', 'frame', 'stack', 'collections' => $pen->framed($symbol, $x, $y, $width, $height, $round),
            'folder', 'package' => $pen->folder($x, $y, $width, $height, $round, ...$title),
            'file' => $pen->tabbed($x, $y, $width, $height),
            'artifact' => $pen->artifact($x, $y, $width, $height, $round),
            'hexagon', 'action', 'process' => [$pen->polygon(self::pointed($symbol, $x, $y, $width, $height))],
            'database' => self::cylinder($x, $y, $width, $height, $line, $back, $stroke),
            'queue' => self::cylinder($x, $y, $width, $height, $line, $back, $stroke, across: 1),
            'cloud' => self::cloud($pen, $x, $y, $width, $height),
            'storage' => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, 50.0)],
            'person' => self::person($x, $y, $width, $height, $line, $back, $stroke),
            'card' => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, 0.0)],
            'label' => [],
            default => [ClassBox::rectangle($x, $y, $width, $height, $line, $back, $stroke, $round)],
        };
        // As PlantUML, which draws these as paths, its limit finder taking them as they run, not as boxes.
        if (\in_array($symbol, ['node', 'database', 'queue', 'folder', 'file', 'package', 'hexagon', 'action', 'process'], strict: true)) {
            // A node's, a hexagon's, an action's and a process's, which PlantUML draws as polygons, 10 wider either side; a folder's and a package's too, when not rounded.
            $taken = \in_array($symbol, ['node', 'hexagon', 'action', 'process'], strict: true) || (0.0 === $round && \in_array($symbol, ['folder', 'package'], strict: true))
                ? Outline::Polygon
                : Outline::Path;
            $outline = \array_map(static fn (Mark $mark): Mark => MarkKind::Polygon === $mark->kind ? $mark->with(['outline' => $taken]) : $mark, $outline);
        }

        return [...$room, ...$outline];
    }

    /**
     * A container's outline: as its symbol's, but a hexagon's, which PlantUML draws as a path, its
     * limit finder taking it as it runs; and a card's, its title ruled off 4 below it.
     *
     * @param array{float, float} $title the size of its title
     * @return list<Mark>
     */
    public static function container(string $symbol, float $x, float $y, float $width, float $height, string $line, string $back, float $stroke, float $round, array $title): array
    {
        $marks = self::outline($symbol, $x, $y, $width, $height, $line, $back, $stroke, $round, $title);

        return match ($symbol) {
            'hexagon' => \array_map(static fn (Mark $mark): Mark => $mark->with(['outline' => Outline::Path]), $marks),
            'card' => [...$marks, new Mark(MarkKind::Polyline, [[$x, $y + $title[1] + 4], [$x + $width, $y + $title[1] + 4]], stroke: $line, strokeWidth: $stroke)],
            default => $marks,
        };
    }

    /**
     * A person's head's radius, as measured on PlantUML's output: 0.21 times the square root of its body's area.
     */
    public static function radius(float $width, float $height): float
    {
        return 0.21 * \sqrt($width * $height);
    }

    /** A person's head's radius, from its whole size: its body below the head. */
    public static function head(float $width, float $height): float
    {
        // The body's height h solves h + 2 * radius(width, h) = height; by a few steps of Newton's method from the whole.
        $body = $height;
        for ($step = 0; $step < 8; ++$step) {
            $f = $body + (2 * self::radius($width, $body)) - $height;
            $slope = 1 + (0.21 * \sqrt($width / \max($body, 1e-9)));
            $body -= $f / $slope;
        }

        return self::radius($width, $body);
    }

    /** @return list<Mark> a person as PlantUML draws one: a head on a body, rounded as much as its head is round */
    private static function person(float $x, float $y, float $width, float $height, string $line, string $back, float $stroke): array
    {
        $radius = self::head($width, $height);

        return [
            DescriptionBox::ellipse($x + ($width / 2) - $radius, $y, 2 * $radius, 2 * $radius, $line, $back, $stroke),
            ClassBox::rectangle($x, $y + (2 * $radius), $width, $height - (2 * $radius), $line, $back, $stroke, 2 * $radius),
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
            // As PlantUML's `USymbolComponent1`: two tabs 10 by 5 half out of its left side, 5 below its top and 10 above its bottom.
            'component1' => [
                ClassBox::rectangle($x, $y, $width, $height, $this->line, $this->back, $this->stroke, $round),
                $this->polygon(ClassBox::corners($x - 5, $y + 5, 10, 5)),
                $this->polygon(ClassBox::corners($x - 5, $bottom - 10, 10, 5)),
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
            // As PlantUML: its copy 4 down and right, behind it, both rounded as a box.
            default => [
                ClassBox::rectangle($x + 4, $y + 4, $width - 4, $height - 4, $this->line, $this->back, $this->stroke, $round),
                ClassBox::rectangle($x, $y, $width - 4, $height - 4, $this->line, $this->back, $this->stroke, $round),
            ],
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

    /**
     * A folder or a package as PlantUML's folder symbol draws one: a box under a tab 6 wider and
     * taller than its title — or with no title, a quarter of the box wide, at least 30, and 10
     * tall — its side slanting 7 down to the box, a line under it; its corners rounded half the
     * rounding given, the tab's top right one a half more, as PlantUML draws them.
     *
     * @return list<Mark>
     */
    private function folder(float $x, float $y, float $width, float $height, float $round, float $titleWidth, float $titleHeight): array
    {
        [$tab, $tall] = 0.0 === $titleWidth ? [\max(30.0, $width / 4), 10.0] : [$titleWidth + 6, $titleHeight + 6];
        [$right, $bottom, $r] = [$x + $width, $y + $height, $round / 2];
        $points = 0.0 === $round
            ? [[$x, $y], [$x + $tab, $y], [$x + $tab + 7, $y + $tall], [$right, $y + $tall], [$right, $bottom], [$x, $bottom]]
            : [
                [$x + $r, $y],
                ...self::arc([$x + $tab - $r, $y], [$x + $tab, $y + $r], $r * 1.5),
                [$x + $tab + 7, $y + $tall],
                ...self::arc([$right - $r, $y + $tall], [$right, $y + $tall + $r], $r),
                ...self::arc([$right, $bottom - $r], [$right - $r, $bottom], $r),
                ...self::arc([$x + $r, $bottom], [$x, $bottom - $r], $r),
                ...self::arc([$x, $y + $r], [$x + $r, $y], $r),
            ];

        return [$this->polygon($points), $this->polyline([[$x, $y + $tall], [$x + $tab + 7, $y + $tall]])];
    }

    /**
     * The points of a circle's arc from one point to another, turning clockwise, as SVG's arc
     * with its sweep set draws the shorter one.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return list<array{float, float}> from the first point to the last
     */
    private static function arc(array $from, array $to, float $radius): array
    {
        [$dx, $dy] = [$to[0] - $from[0], $to[1] - $from[1]];
        $chord = \hypot($dx, $dy);
        $rise = \sqrt(\max(0.0, $radius ** 2 - ($chord / 2) ** 2));
        [$cx, $cy] = [(($from[0] + $to[0]) / 2) - (($dy / $chord) * $rise), (($from[1] + $to[1]) / 2) + (($dx / $chord) * $rise)];
        $start = \atan2($from[1] - $cy, $from[0] - $cx);
        $turn = \fmod(\atan2($to[1] - $cy, $to[0] - $cx) - $start + (4 * \M_PI), 2 * \M_PI);
        $points = [];
        for ($step = 0; $step <= 4; ++$step) {
            $angle = $start + (($turn * $step) / 4);
            $points[] = [$cx + ($radius * \cos($angle)), $cy + ($radius * \sin($angle))];
        }
        [$points[0], $points[4]] = [$from, $to];

        return $points;
    }

    /**
     * An artifact as PlantUML draws one: a box, a document 12 by 14 with its corner folded 6, 5
     * in from the box's top right, taken as the polygon it is drawn as, 10 wider either side.
     *
     * @return list<Mark>
     */
    private function artifact(float $x, float $y, float $width, float $height, float $round): array
    {
        [$left, $top] = [$x + $width - 17, $y + 5];

        return [
            ClassBox::rectangle($x, $y, $width, $height, $this->line, $this->back, $this->stroke, $round),
            $this->polygon([[$left, $top], [$left, $top + 14], [$left + 12, $top + 14], [$left + 12, $top + 6], [$left + 6, $top]])->with(['outline' => Outline::Polygon]),
            $this->polyline([[$left + 6, $top], [$left + 6, $top + 6], [$left + 12, $top + 6]]),
        ];
    }

    /** @return list<Mark> a box with a folded corner: a file */
    private function tabbed(float $x, float $y, float $width, float $height): array
    {
        $right = $x + $width;
        $bottom = $y + $height;

        return [
            $this->polygon([[$x, $y], [$right - 10, $y], [$right, $y + 10], [$right, $bottom], [$x, $bottom]]),
            $this->polyline([[$right - 10, $y], [$right - 10, $y + 10], [$right, $y + 10]]),
        ];
    }

    /** @return list<array{float, float}> a hexagon, an action's arrow, a process's chevron */
    private static function pointed(string $symbol, float $x, float $y, float $width, float $height): array
    {
        $right = $x + $width;
        $bottom = $y + $height;
        $middle = $y + ($height / 2);

        return match ($symbol) {
            'hexagon' => [[$x + ($width / 4), $y], [$right - ($width / 4), $y], [$right, $middle], [$right - ($width / 4), $bottom], [$x + ($width / 4), $bottom], [$x, $middle]],
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

    /**
     * A cloud as PlantUML draws it: bubbles along a box 8 in from its sides, a few more on
     * longer sides, each a curve bulging out, the whole jittered by Java's random numbers
     * seeded with its size — and the room its curves' handles reach, which PlantUML counts.
     *
     * @return list<Mark>
     */
    private static function cloud(self $pen, float $x, float $y, float $width, float $height): array
    {
        $random = new JavaRandom((int) $width + (7919 * (int) $height));
        $points = self::cloudPoints($random, $width, $height);
        $outline = [[$x + $points[0][0], $y + $points[0][1]]];
        $handles = [];
        for ($i = 0, $count = \count($points); $i < ($count - 1); ++$i) {
            $length = self::distance($points[$i], $points[$i + 1]);
            $share = self::between($random, 0.25, 0.35);
            $first = self::along($points[$i], $points[$i + 1], $length * $share, -$length * self::between($random, 0.4, 0.55));
            $second = self::along($points[$i], $points[$i + 1], $length * (1 - $share), -$length * self::between($random, 0.4, 0.55));
            \array_push($handles, $first, $second);
            \array_push($outline, ...self::curve($x, $y, $points[$i], $first, $second, $points[$i + 1]));
        }
        $xs = \array_column($handles, 0);
        $ys = \array_column($handles, 1);

        return [
            $pen->polygon($outline),
            new Mark(MarkKind::Polyline, [[$x + \min($xs), $y + \min($ys)], [$x + \max($xs), $y + \max($ys)]], stroke: 'none', strokeWidth: 0.0),
        ];
    }

    /**
     * The points a cloud's curves join: bubbles along each side of a box 8 in, the sides
     * pulled in 7 from the corners on a cloud over 100 each way, closed back to the first.
     *
     * @return non-empty-list<array{float, float}>
     */
    private static function cloudPoints(JavaRandom $random, float $width, float $height): array
    {
        $bubble = (\max($width, $height) / 11) > 16 ? \max($width, $height) / 16 : 11.0;
        [$a, $b, $c, $d] = [[8.0, 8.0], [$width - 8, 8.0], [$width - 8, $height - 8], [8.0, $height - 8]];
        $sides =
            $width > 100 && $height > 100
                ? [
                    [[$a[0] + 7, $a[1]], [$b[0] - 7, $b[1]], [$b[0], $b[1] + 7]],
                    [[$b[0], $b[1] + 7], [$c[0], $c[1] - 7], [$c[0] - 7, $c[1]]],
                    [[$c[0] - 7, $c[1]], [$d[0] + 7, $d[1]], [$d[0], $d[1] - 7]],
                    [[$d[0], $d[1] - 7], [$a[0], $a[1] + 7], [$a[0] + 7, $a[1]]],
                ]
                : [[$a, $b, null], [$b, $c, null], [$c, $d, null], [$d, $a, null]];
        $points = [];
        foreach ($sides as [$from, $to, $corner]) {
            $middle = self::along($from, $to, self::distance($from, $to) / 2, -self::between($random, 1, 1 + \min(12, $bubble * 0.8)));
            self::bubbles($random, $points, $from, $middle, $bubble);
            self::bubbles($random, $points, $middle, $to, $bubble);
            if (null !== $corner) {
                $points[] = $corner;
            }
        }
        $points[] = $points[0] ?? [8.0, 8.0];

        return $points;
    }

    /**
     * A cubic curve's points, eight steps from its start, moved to a point.
     *
     * @param array{float, float} $start
     * @param array{float, float} $first
     * @param array{float, float} $second
     * @param array{float, float} $end
     * @return list<array{float, float}>
     */
    private static function curve(float $x, float $y, array $start, array $first, array $second, array $end): array
    {
        $points = [];
        for ($step = 1; $step <= 8; ++$step) {
            $t = $step / 8;
            $u = 1 - $t;
            [$k0, $k1, $k2, $k3] = [$u * $u * $u, 3 * $u * $u * $t, 3 * $u * $t * $t, $t * $t * $t];
            $points[] = [$x + ($k0 * $start[0]) + ($k1 * $first[0]) + ($k2 * $second[0]) + ($k3 * $end[0]), $y + ($k0 * $start[1]) + ($k1 * $first[1]) + ($k2 * $second[1]) + ($k3 * $end[1])];
        }

        return $points;
    }

    /**
     * Points every bubble's length along a stretch, each jittered by up to a fifth of it.
     *
     * @param list<array{float, float}> $points
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private static function bubbles(JavaRandom $random, array &$points, array $from, array $to, float $size): void
    {
        $length = self::distance($from, $to);
        $count = (int) ($length / $size);
        if (0 === $count) {
            $size = $length / 2;
            $count = (int) ($length / $size);
        }
        for ($i = 0; $i < $count; ++$i) {
            [$px, $py] = self::along($from, $to, ($i * $length) / $count, 0.0);
            $points[] = [$px + ($size * 0.2 * $random->nextDouble()), $py + ($size * 0.2 * $random->nextDouble())];
        }
    }

    /**
     * A point a distance along a stretch and a distance across it, to its left going along.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function along(array $from, array $to, float $a, float $b): array
    {
        $length = \max(1e-9, self::distance($from, $to));
        [$ux, $uy] = [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length];

        return [$from[0] + ($a * $ux) - ($b * $uy), $from[1] + ($a * $uy) + ($b * $ux)];
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    private static function distance(array $a, array $b): float
    {
        return \hypot($b[0] - $a[0], $b[1] - $a[1]);
    }

    private static function between(JavaRandom $random, float $low, float $high): float
    {
        return ($random->nextDouble() * ($high - $low)) + $low;
    }
}
