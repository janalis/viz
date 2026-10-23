<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

/**
 * A node's outline, in points around its centre, for the shapes of dot's documented table:
 * the outline itself, closed and filled; any extra outlines drawn around it (peripheries);
 * and decoration lines drawn on top, such as a note's fold.
 */
final readonly class DotShape
{
    /** Shapes drawn without an outline. */
    public const array BARE = ['plaintext', 'plain', 'none'];

    /**
     * How much larger than its label each shape must be to hold it.
     *
     * @var array<string, float>
     */
    private const array GROWTH = [
        'ellipse' => \M_SQRT2,
        'oval' => \M_SQRT2,
        'circle' => \M_SQRT2,
        'doublecircle' => \M_SQRT2,
        'Mcircle' => \M_SQRT2,
        'egg' => \M_SQRT2,
        'diamond' => 2.0,
        'Mdiamond' => 2.0,
        'star' => 2.0,
        // As measured on `dot`'s output: a polygon of n sides √2 / cos(π/n) times as big.
        'triangle' => 2.828_427,
        'invtriangle' => 2.828_427,
        'pentagon' => 1.748_064,
        'hexagon' => 1.632_993,
        'septagon' => 1.569_595,
        'octagon' => 1.530_734,
        'doubleoctagon' => 1.530_734,
        'tripleoctagon' => 1.530_734,
        'house' => 1.748_064,
        'invhouse' => 1.748_064,
        'trapezium' => 2.0,
        'invtrapezium' => 2.0,
        'parallelogram' => 2.0,
    ];

    /**
     * @param list<array{float, float}> $outline
     * @param list<list<array{float, float}>> $peripheries outlines around it, innermost first
     * @param list<list<array{float, float}>> $decorations open lines drawn over it
     */
    public function __construct(
        public array $outline,
        public array $peripheries = [],
        public array $decorations = [],
    ) {}

    /** How much larger than its label a shape must be. */
    public static function growth(string $shape): float
    {
        return self::GROWTH[$shape] ?? 1.0;
    }

    /**
     * How much larger than its label a shape must be across, where it differs from down: a
     * slanted or distorted shape's, as measured on `dot`'s output.
     */
    public static function across(string $shape): float
    {
        return match ($shape) {
            'egg' => 1.506_05,
            'parallelogram' => 2.073_49,
            default => self::growth($shape),
        };
    }

    /** Whether a shape is round, so a regular one is as wide as it is tall. */
    public static function round(string $shape): bool
    {
        return \in_array($shape, ['circle', 'doublecircle', 'Mcircle', 'point'], strict: true);
    }

    /** @param ?int $peripheries how many outlines it is drawn with, its shape's own when not written */
    public static function of(string $shape, float $width, float $height, ?int $peripheries = null, int $sides = 4, float $skew = 0.0, float $distortion = 0.0, float $orientation = 0.0): self
    {
        [$w, $h] = [$width / 2, $height / 2];
        $outline = match ($shape) {
            'ellipse', 'oval', 'circle', 'doublecircle', 'Mcircle', 'point', 'egg' => self::ellipse($w, $h),
            'diamond', 'Mdiamond' => [[0.0, -$h], [$w, 0.0], [0.0, $h], [-$w, 0.0]],
            'triangle' => self::spread(3, $w, $h),
            'invtriangle' => \array_map(static fn (array $point): array => [$point[0], -$point[1]], self::spread(3, $w, $h)),
            // As `dot` is seen to draw them: a trapezium's top 0.584 as wide as its base, a parallelogram leaning past its box, a house a pentagon on its base's corners.
            'trapezium' => [[-$w * 0.584, -$h], [$w * 0.584, -$h], [$w, $h], [-$w, $h]],
            'invtrapezium' => [[-$w, -$h], [$w, -$h], [$w * 0.584, $h], [-$w * 0.584, $h]],
            'parallelogram' => [[-$w * 0.5757, -$h], [$w * 1.0735, -$h], [$w * 0.6493, $h], [-$w, $h]],
            'house' => self::house($w, $h),
            'invhouse' => \array_map(static fn (array $point): array => [$point[0], -$point[1]], self::house($w, $h)),
            'pentagon' => self::spread(5, $w, $h),
            'hexagon' => self::spread(6, $w, $h),
            'septagon' => self::spread(7, $w, $h),
            'octagon', 'doubleoctagon', 'tripleoctagon' => self::spread(8, $w, $h),
            'star' => self::star($w, $h),
            // As `dot` is seen to draw it: 6 in from its top and bottom, its point 12 long.
            'cds' => [[-$w, -$h + 6], [$w - 12, -$h + 6], [$w, 0.0], [$w - 12, $h - 6], [-$w, $h - 6]],
            'polygon' => self::polygon($sides, $w, $h, $skew, $distortion, $orientation),
            default => [[-$w, -$h], [$w, -$h], [$w, $h], [-$w, $h]],
        };
        $count = $peripheries ?? match ($shape) {
            'doublecircle', 'doubleoctagon' => 2,
            'tripleoctagon' => 3,
            default => 1,
        };
        $rings = [];
        $curved = \in_array($shape, ['ellipse', 'oval', 'circle', 'doublecircle', 'Mcircle', 'point', 'egg'], strict: true);
        for ($ring = 1; $ring < $count; ++$ring) {
            $grow = 4.0 * $ring;
            $rings[] = $curved ? \array_map(static fn (array $point): array => self::outward($point, $w, $h, $grow), $outline) : self::offset($outline, $grow);
        }

        return new self($outline, $rings, self::decorations($shape, $w, $h));
    }

    /**
     * The size a shape takes with its outlines around it, as `dot` is seen to size it: each
     * outline 4 outside the one within, along every side.
     *
     * @return array{float, float}
     */
    public static function grown(string $shape, float $width, float $height, ?int $peripheries = null): array
    {
        $drawn = self::of($shape, $width, $height, $peripheries);
        if ([] === $drawn->peripheries) {
            return [$width, $height];
        }
        [$outerWidth, $outerHeight] = self::extent($drawn->peripheries[\count($drawn->peripheries) - 1]);
        [$ownWidth, $ownHeight] = self::extent($drawn->outline);

        return [$width + $outerWidth - $ownWidth, $height + $outerHeight - $ownHeight];
    }

    /**
     * @param list<array{float, float}> $polygon
     * @return array{float, float} how wide and tall it reaches
     */
    private static function extent(array $polygon): array
    {
        $xs = \array_column($polygon, 0);
        $ys = \array_column($polygon, 1);

        return [\max($xs) - \min($xs), \max($ys) - \min($ys)];
    }

    /**
     * The size of the outline within a shape's outlines that take a size together.
     *
     * @return array{float, float}
     */
    public static function inner(string $shape, float $width, float $height, ?int $peripheries = null): array
    {
        [$innerWidth, $innerHeight] = [$width, $height];
        for ($step = 0; $step < 8; ++$step) {
            [$grownWidth, $grownHeight] = self::grown($shape, $innerWidth, $innerHeight, $peripheries);
            [$innerWidth, $innerHeight] = [\max(0.0, $innerWidth + $width - $grownWidth), \max(0.0, $innerHeight + $height - $grownHeight)];
        }

        return [$innerWidth, $innerHeight];
    }

    /** Whether a point, around the centre, falls inside the outermost outline. */
    public function contains(float $x, float $y): bool
    {
        $polygon = [] === $this->peripheries ? $this->outline : $this->peripheries[\count($this->peripheries) - 1];
        $inside = false;
        $count = \count($polygon);
        for ($index = 0, $previous = $count - 1; $index < $count; $previous = $index++) {
            [$x1, $y1] = $polygon[$index];
            [$x2, $y2] = $polygon[$previous];
            if ($y1 > $y !== $y2 > $y && $x < (((($x2 - $x1) * ($y - $y1)) / ($y2 - $y1)) + $x1)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** @return list<array{float, float}> */
    private static function ellipse(float $w, float $h): array
    {
        $points = [];
        for ($step = 0; $step < 48; ++$step) {
            $angle = (2 * \M_PI * $step) / 48;
            $points[] = [$w * \cos($angle), $h * \sin($angle)];
        }

        return $points;
    }

    /**
     * A regular polygon as `dot` is seen to draw one: a vertex at its top when its sides are
     * odd, a side when even, stretched across and down until it reaches its box's sides and top
     * or bottom.
     *
     * @return list<array{float, float}>
     */
    private static function spread(int $sides, float $w, float $h): array
    {
        $start = 0 === ($sides % 2) ? \M_PI / $sides : 0.0;
        $unit = [];
        for ($side = 0; $side < $sides; ++$side) {
            $angle = $start + (((2 * \M_PI) / $sides) * $side) - (\M_PI / 2);
            $unit[] = [\cos($angle), \sin($angle)];
        }
        $across = \max(\array_map(static fn (array $point): float => \abs($point[0]), $unit));
        $down = \max(\array_map(static fn (array $point): float => \abs($point[1]), $unit));

        return \array_map(static fn (array $point): array => [($w * $point[0]) / $across, ($h * $point[1]) / $down], $unit);
    }

    /** @return list<array{float, float}> a pentagon stretched to its box, its base's corners out to the box's sides */
    private static function house(float $w, float $h): array
    {
        return \array_map(static fn (array $point): array => $point[1] > 0 ? [($point[0] <=> 0) * $w * 0.9991, $point[1]] : $point, self::spread(5, $w, $h));
    }

    /** @return list<array{float, float}> a regular polygon inscribed in the ellipse, its first vertex at $start degrees from the top */
    private static function regular(int $sides, float $w, float $h, float $start): array
    {
        $points = [];
        for ($side = 0; $side < $sides; ++$side) {
            $angle = \deg2rad($start + ((360 / $sides) * $side)) - (\M_PI / 2);
            $points[] = [$w * \cos($angle), $h * \sin($angle)];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function star(float $w, float $h): array
    {
        $points = [];
        for ($vertex = 0; $vertex < 10; ++$vertex) {
            $angle = ((\M_PI / 5) * $vertex) - (\M_PI / 2);
            $radius = 0 === ($vertex % 2) ? 1.0 : 0.4;
            $points[] = [$w * $radius * \cos($angle), $h * $radius * \sin($angle)];
        }

        return $points;
    }

    /** @return list<array{float, float}> a polygon by sides, skewed, distorted and turned as DOT's attributes ask */
    private static function polygon(int $sides, float $w, float $h, float $skew, float $distortion, float $orientation): array
    {
        $sides = \max(3, \min(100, $sides));
        $points = [];
        foreach (self::regular($sides, $w, $h, (180 / $sides) + $orientation) as [$x, $y]) {
            // Skew shifts the top right and the bottom left; distortion widens the top and narrows the bottom.
            $ratio = $h > 0 ? -$y / $h : 0.0;
            $points[] = [($x * (1 + ($distortion * $ratio))) + ($skew * $w * $ratio), $y];
        }

        return $points;
    }

    /**
     * A polygon's sides each moved outward by a distance, its corners where they meet.
     *
     * @param list<array{float, float}> $polygon
     * @return list<array{float, float}>
     */
    private static function offset(array $polygon, float $distance): array
    {
        $count = \count($polygon);
        $area = 0.0;
        for ($index = 0; $index < $count; ++$index) {
            [$x1, $y1] = $polygon[$index];
            [$x2, $y2] = $polygon[($index + 1) % $count];
            $area += ($x1 * $y2) - ($x2 * $y1);
        }
        $turn = $area < 0 ? -1.0 : 1.0;
        $moved = [];
        foreach ($polygon as $index => $point) {
            [$ax, $ay] = self::normal($polygon[($index + $count - 1) % $count], $point, $turn);
            [$bx, $by] = self::normal($point, $polygon[($index + 1) % $count], $turn);
            $join = 1 + ($ax * $bx) + ($ay * $by);
            $moved[] = $join > 1e-9
                ? [$point[0] + (($distance * ($ax + $bx)) / $join), $point[1] + (($distance * ($ay + $by)) / $join)]
                : [$point[0] + ($distance * $ax), $point[1] + ($distance * $ay)];
        }

        return $moved;
    }

    /**
     * The unit normal of a side, outward for a polygon turning the way given.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function normal(array $from, array $to, float $turn): array
    {
        [$dx, $dy] = [$to[0] - $from[0], $to[1] - $from[1]];
        $length = \hypot($dx, $dy);

        return $length > 0 ? [($turn * $dy) / $length, (-$turn * $dx) / $length] : [0.0, 0.0];
    }

    /**
     * A point pushed outward from the centre by a distance, for a periphery.
     *
     * @param array{float, float} $point
     * @return array{float, float}
     */
    private static function outward(array $point, float $w, float $h, float $grow): array
    {
        [$x, $y] = $point;

        return [$w > 0 ? ($x * ($w + $grow)) / $w : $x, $h > 0 ? ($y * ($h + $grow)) / $h : $y];
    }

    /** @return list<list<array{float, float}>> */
    private static function decorations(string $shape, float $w, float $h): array
    {
        $fold = \min(10.0, $w / 3, $h / 3);

        return match ($shape) {
            'note' => [[[$w - $fold, -$h], [$w - $fold, -$h + $fold], [$w, -$h + $fold]]],
            'tab' => [[[-$w, -$h + $fold], [-$w + (3 * $fold), -$h + $fold], [-$w + (3 * $fold), -$h]]],
            'folder' => [[[-$w, -$h + $fold], [$w * 0.2, -$h + $fold]]],
            'box3d' => [[[-$w + $fold, -$h], [-$w + $fold, -$h - $fold], [$w + $fold, -$h - $fold], [$w + $fold, $h - $fold], [$w, $h]], [[$w, -$h], [$w + $fold, -$h - $fold]]],
            'component' => [
                [[-$w - 4, -$h / 2], [-$w + 4, -$h / 2], [-$w + 4, (-$h / 2) + 6], [-$w - 4, (-$h / 2) + 6], [-$w - 4, -$h / 2]],
                [[-$w - 4, ($h / 2) - 6], [-$w + 4, ($h / 2) - 6], [-$w + 4, $h / 2], [-$w - 4, $h / 2], [-$w - 4, ($h / 2) - 6]],
            ],
            'cylinder' => [\array_map(static fn (int $step): array => [$w * \cos((\M_PI * $step) / 16), -$h + ($fold * \sin((\M_PI * $step) / 16))], \range(start: 0, end: 16))],
            'underline' => [[[-$w, $h], [$w, $h]]],
            default => self::marked($shape, $w, $h, $fold),
        };
    }

    /**
     * The marks of the M shapes, which UML diagrams use for a start or an end.
     *
     * @return list<list<array{float, float}>>
     */
    private static function marked(string $shape, float $w, float $h, float $fold): array
    {
        if ($w <= 0 || $h <= 0) {
            return [];
        }

        return match ($shape) {
            'Msquare' => [[[-$w, -$h + $fold], [-$w + $fold, -$h]], [[$w - $fold, -$h], [$w, -$h + $fold]], [[$w, $h - $fold], [$w - $fold, $h]], [[-$w + $fold, $h], [-$w, $h - $fold]]],
            // Each tip cut off where the diamond is as wide as the cut is deep.
            'Mdiamond' => [
                [[(-$fold * $w) / $h, -$h + $fold], [($fold * $w) / $h, -$h + $fold]],
                [[(-$fold * $w) / $h, $h - $fold], [($fold * $w) / $h, $h - $fold]],
                [[-$w + $fold, (-$fold * $h) / $w], [-$w + $fold, ($fold * $h) / $w]],
                [[$w - $fold, (-$fold * $h) / $w], [$w - $fold, ($fold * $h) / $w]],
            ],
            'Mcircle' => [[[-$w * 0.7, -$h * 0.7], [$w * 0.7, -$h * 0.7]], [[-$w * 0.7, $h * 0.7], [$w * 0.7, $h * 0.7]]],
            default => [],
        };
    }
}
