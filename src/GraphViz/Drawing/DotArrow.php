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
        'box' => 8.0,
        'crow' => 10.0,
        'vee' => 10.0,
        'tee' => 4.0,
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

    /** How far the arrowhead reaches back from its tip, in points. */
    public function length(float $size): float
    {
        return \array_sum(\array_map(static fn (array $part): float => self::LENGTHS[$part[0]], $this->parts)) * $size;
    }

    /**
     * The arrowhead's marks with its tip at a point, pointing along a unit direction.
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
        [$x, $y] = $tip;
        foreach ($this->parts as [$shape, $open, $side]) {
            $length = self::LENGTHS[$shape] * $size;
            $width = 3.5 * $size;
            $back = static fn (float $along, float $across): array => [$x - ($dx * $along) + ($nx * $across), $y - ($dy * $along) + ($ny * $across)];
            $left = 'r' === $side ? 0.0 : $width;
            $right = 'l' === $side ? 0.0 : $width;
            $points = match ($shape) {
                'normal', 'curve' => [$back(along: 0, across: 0), $back($length, $left), $back($length, -$right)],
                'inv', 'icurve' => [$back(along: 0, across: $left), $back(along: 0, across: -$right), $back($length, across: 0)],
                'vee' => [$back(along: 0, across: 0), $back($length, $left), $back($length * 0.6, across: 0), $back($length, -$right)],
                'crow' => [$back(along: 0, across: $left), $back($length * 0.4, across: 0), $back(along: 0, across: -$right), $back($length, across: 0)],
                'diamond' => [$back(along: 0, across: 0), $back($length / 2, $left), $back($length, across: 0), $back($length / 2, -$right)],
                'box' => [$back(along: 0, across: $left), $back($length, $left), $back($length, -$right), $back(along: 0, across: -$right)],
                'tee' => [$back(along: 0, across: $left * 1.5), $back($length, $left * 1.5), $back($length, -$right * 1.5), $back(along: 0, across: -$right * 1.5)],
                'dot' => \array_map(
                    static fn (int $step): array => $back(($length / 2) + (($length / 2) * \cos((\M_PI * $step) / 8)), ($length / 2) * \sin((\M_PI * $step) / 8)),
                    \range(start: 0, end: 15),
                ),
                default => [],
            };
            if ([] !== $points) {
                $marks[] = new Mark(MarkKind::Polygon, $points, stroke: $color, fill: $open ? $background : $color, strokeWidth: $penWidth);
            }
            [$x, $y] = $back($length, across: 0);
        }

        return $marks;
    }
}
