<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A DOT arrowhead, by its documented grammar: up to four shapes, each after optional `o`
 * (drawn open) and `l` or `r` (only that half), from the tip back; with the old names
 * `empty`, `invempty`, `ediamond`, `open` and `halfopen`. Drawn in points.
 */
final readonly class DotArrow
{
    private const array SHAPES = ['normal', 'inv', 'dot', 'diamond', 'box', 'crow', 'vee', 'tee', 'curve', 'icurve', 'none'];
    private const array ALIASES = ['empty' => 'onormal', 'invempty' => 'oinv', 'ediamond' => 'odiamond', 'open' => 'vee', 'halfopen' => 'lvee', 'invdot' => 'invdot', 'invodot' => 'invodot'];
    private const array LENGTHS = [
        'normal' => 10.0,
        'inv' => 10.0,
        'dot' => 8.0,
        'diamond' => 12.0,
        'box' => 10.0,
        'crow' => 10.0,
        'vee' => 10.0,
        'tee' => 5.0,
        'curve' => 10.0,
        'icurve' => 10.0,
        'none' => 0.0,
    ];

    /** @param list<array{string, bool, string}> $parts each shape, whether it is open, and its side: '' for both, 'l' or 'r' */
    public function __construct(
        public array $parts,
    ) {}

    public static function parse(string $name): self
    {
        $name = \strtolower(\trim($name));
        $name = self::ALIASES[$name] ?? ('' === $name ? 'normal' : $name);
        $parts = [];
        while ('' !== $name && \count($parts) < 4) {
            $match = [];
            if (1 !== \preg_match('/^(o?)([lr]?)('.\implode('|', self::SHAPES).')/', $name, $match)) {
                break;
            }
            $parts[] = [$match[3], 'o' === $match[1], $match[2]];
            $name = \substr($name, \strlen($match[0]));
        }

        return new self([] === $parts ? [['normal', false, '']] : $parts);
    }

    /** How far the arrowhead reaches back from the end of its line, in points: its tip held back as {@see pull()} holds it, and its shapes. */
    public function length(float $size, float $penWidth = 0.0): float
    {
        return $this->pull($penWidth) + (\array_sum(\array_map(static fn (array $part): float => self::LENGTHS[$part[0]], $this->parts)) * $size);
    }

    /**
     * How far back from the end of its line the arrowhead's tip stands, as `dot` is seen to set
     * it: as far as its stroke reaches past its corner there — half the pen past a flat end,
     * further past a sharp one.
     */
    private function pull(float $penWidth): float
    {
        [$shape, , $side] = $this->parts[0];
        $half = $penWidth / 2;

        return match (true) {
            '' !== $side && 'normal' === $shape => 0.17 * $penWidth,
            '' !== $side && 'vee' === $shape => 0.21 * $penWidth,
            'normal' === $shape => $half / \sin(\atan(0.35)),
            'vee' === $shape => $half / \sin(\atan(0.45)),
            'diamond' === $shape => $half / \sin(\atan(4 / 6)),
            'crow' === $shape => 0.34 * $penWidth,
            \in_array($shape, ['inv', 'dot', 'box'], strict: true) => $half,
            default => 0.0,
        };
    }

    /**
     * The arrowhead's marks with the end of its line at a point, pointing along a unit
     * direction, its shapes as `dot` is seen to draw them: a normal 7 wide, a vee and a crow 9
     * wide notched 5 from the line's end, a diamond 8 wide, a box 8 across with a stem 2 long,
     * a tee a bar 10 wide and 2 thick on a stem 5 long.
     *
     * @param array{float, float} $tip
     * @param array{float, float} $direction
     * @return list<Mark>
     */
    public function marks(array $tip, array $direction, float $size, string $color, string $background, float $penWidth): array
    {
        $marks = [];
        [$dx, $dy] = $direction;
        // The normal points to the arrow's left as it travels.
        [$nx, $ny] = [$dy, -$dx];
        $pull = $this->pull($penWidth);
        [$x, $y] = [$tip[0] - ($dx * $pull), $tip[1] - ($dy * $pull)];
        foreach ($this->parts as [$shape, $open, $side]) {
            $length = self::LENGTHS[$shape] * $size;
            $back = static fn (float $along, float $across): array => [$x - ($dx * $along) + ($nx * $across), $y - ($dy * $along) + ($ny * $across)];
            $half = static fn (float $width): array => ['r' === $side ? 0.0 : $width * $size, 'l' === $side ? 0.0 : $width * $size];
            [$left, $right] = $half(match ($shape) {
                'vee', 'crow' => 4.5,
                'diamond', 'box' => 4.0,
                'tee' => 5.0,
                default => 3.5,
            });
            // The notch of a vee or a crow stands 5 from the end of the line, wherever its tip is held back to.
            $notch = (5.0 * $size) - $pull;
            $points = match ($shape) {
                'normal', 'curve' => [$back(along: 0, across: 0), $back($length, $left), $back($length, -$right)],
                'inv', 'icurve' => [$back(along: 0, across: $left), $back(along: 0, across: -$right), $back($length, across: 0)],
                'vee' => [$back(along: 0, across: 0), $back($length, $left), $back($notch, across: 0), $back($length, -$right)],
                'crow' => [
                    $back($length, across: 0),
                    $back(along: 0, across: $left),
                    $back($notch, across: 0),
                    $back(0.33 * $size, across: 0),
                    $back($notch, across: 0),
                    $back(along: 0, across: -$right),
                ],
                'diamond' => [$back(along: 0, across: 0), $back($length / 2, $left), $back($length, across: 0), $back($length / 2, -$right)],
                'box' => [$back(along: 0, across: $left), $back(8 * $size, $left), $back(8 * $size, -$right), $back(along: 0, across: -$right)],
                'tee' => [$back($size, $left), $back(3 * $size, $left), $back(3 * $size, -$right), $back($size, -$right)],
                'dot' => \array_map(
                    static fn (int $step): array => $back(($length / 2) + (($length / 2) * \cos((\M_PI * $step) / 8)), ($length / 2) * \sin((\M_PI * $step) / 8)),
                    \range(start: 0, end: 15),
                ),
                default => [],
            };
            if ([] !== $points) {
                $marks[] = new Mark(MarkKind::Polygon, $points, stroke: $color, fill: $open ? $background : $color, strokeWidth: $penWidth);
            }
            // As `dot` draws them, a box stands on a stem, and a tee's bar on one running to the end of the line.
            $stem = match ($shape) {
                'box' => [$back(8 * $size, across: 0), $back($length, across: 0)],
                'tee' => [$back(along: 0, across: 0), $back($length, across: 0)],
                default => [],
            };
            if ([] !== $stem) {
                $marks[] = new Mark(MarkKind::Polyline, $stem, stroke: $color, strokeWidth: $penWidth);
            }
            [$x, $y] = $back($length, across: 0);
            $pull = 0.0;
        }

        return $marks;
    }
}
