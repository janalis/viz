<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/**
 * An SVG path's `d`, flattened: each subpath an outline of points, its curves and arcs cut into
 * straight steps — as a picture PlantUML ships as SVG, its emoji, is drawn by every backend.
 */
final class SvgPath
{
    private const int STEPS = 8;

    private int $at = 0;
    /** @var list<list<array{float, float}>> */
    private array $outlines = [];
    /** @var list<array{float, float}> */
    private array $points = [];
    /** @var array{float, float} */
    private array $current = [0.0, 0.0];
    /** @var array{float, float} */
    private array $start = [0.0, 0.0];
    /** @var ?array{float, float} the last curve's control point nearest its end, for `S` and `T` to mirror */
    private ?array $control = null;

    private function __construct(
        private readonly string $data,
    ) {}

    /** @return list<list<array{float, float}>> */
    public static function outlines(string $data): array
    {
        $path = new self($data);
        $command = '';
        while (null !== ($next = $path->command($command))) {
            $command = $path->run($next);
        }
        $path->close();

        return $path->outlines;
    }

    /** Carries out one command, its numbers read; returns the command further numbers repeat. */
    private function run(string $command): string
    {
        $origin = \ctype_lower($command) ? $this->current : [0.0, 0.0];
        $previous = $this->control;
        $this->control = null;
        $upper = \strtoupper($command);
        match ($upper) {
            'M' => $this->move($this->point($origin)),
            'L' => $this->line($this->point($origin)),
            'H' => $this->line([$origin[0] + $this->number(), $this->current[1]]),
            'V' => $this->line([$this->current[0], $origin[1] + $this->number()]),
            'C' => $this->cubic($this->point($origin), $this->point($origin), $this->point($origin)),
            'S' => $this->cubic($this->mirrored($previous), $this->point($origin), $this->point($origin)),
            'Q' => $this->quadratic($this->point($origin), $this->point($origin)),
            'T' => $this->quadratic($this->mirrored($previous), $this->point($origin)),
            'A' => $this->arc($origin),
            default => $this->end(),
        };

        return match ($upper) {
            // Further pairs after a move are lines.
            'M' => \ctype_lower($command) ? 'l' : 'L',
            'Z' => '',
            default => $command,
        };
    }

    /** @param array{float, float} $to */
    private function move(array $to): void
    {
        $this->close();
        $this->current = $to;
        $this->start = $to;
        $this->points = [$to];
    }

    /** @param array{float, float} $to */
    private function line(array $to): void
    {
        $this->current = $to;
        $this->points[] = $to;
    }

    /** `Z`: back to the subpath's start, where the next one starts unless it moves. */
    private function end(): void
    {
        $this->close();
        $this->current = $this->start;
        $this->points = [$this->start];
    }

    private function close(): void
    {
        if (\count($this->points) > 1) {
            $this->outlines[] = $this->points;
        }
        $this->points = [];
    }

    /**
     * @param ?array{float, float} $previous
     * @return array{float, float} the last curve's control point mirrored about the current one, or the current one after no curve
     */
    private function mirrored(?array $previous): array
    {
        return null === $previous ? $this->current : [(2 * $this->current[0]) - $previous[0], (2 * $this->current[1]) - $previous[1]];
    }

    /**
     * @param array{float, float} $first
     * @param array{float, float} $second
     * @param array{float, float} $end
     */
    private function cubic(array $first, array $second, array $end): void
    {
        $from = $this->current;
        for ($step = 1; $step <= self::STEPS; ++$step) {
            $t = $step / self::STEPS;
            $u = 1 - $t;
            $this->points[] = [
                ($u * $u * $u * $from[0]) + (3 * $u * $u * $t * $first[0]) + (3 * $u * $t * $t * $second[0]) + ($t * $t * $t * $end[0]),
                ($u * $u * $u * $from[1]) + (3 * $u * $u * $t * $first[1]) + (3 * $u * $t * $t * $second[1]) + ($t * $t * $t * $end[1]),
            ];
        }
        $this->points[\count($this->points) - 1] = $end;
        $this->current = $end;
        $this->control = $second;
    }

    /**
     * A quadratic curve, as the cubic it is.
     *
     * @param array{float, float} $middle
     * @param array{float, float} $end
     */
    private function quadratic(array $middle, array $end): void
    {
        $from = $this->current;
        $this->cubic(
            [$from[0] + ((2 / 3) * ($middle[0] - $from[0])), $from[1] + ((2 / 3) * ($middle[1] - $from[1]))],
            [$end[0] + ((2 / 3) * ($middle[0] - $end[0])), $end[1] + ((2 / 3) * ($middle[1] - $end[1]))],
            $end,
        );
        $this->control = $middle;
    }

    /**
     * An elliptical arc, from its ends to its centre as the SVG specification's appendix converts it.
     *
     * @param array{float, float} $origin
     */
    private function arc(array $origin): void
    {
        [$rx, $ry, $rotation] = [\abs($this->number()), \abs($this->number()), \deg2rad($this->number())];
        [$large, $sweep] = [$this->flag(), $this->flag()];
        $end = $this->point($origin);
        $from = $this->current;
        $this->current = $end;
        if (0.0 === $rx || 0.0 === $ry || $from === $end) {
            $this->points[] = $end;

            return;
        }
        $this->ellipse($from, $end, [$rx, $ry, $rotation], $large === $sweep ? -1.0 : 1.0, $sweep ? 1.0 : -1.0);
        $this->points[] = $end;
    }

    /**
     * The arc's points between its ends, about the centre its radii put it on.
     *
     * @param array{float, float} $from
     * @param array{float, float} $end
     * @param array{float, float, float} $radii its radii, and its turn in radians
     * @param float $side 1 or -1, which centre: -1 where the large-arc and sweep flags agree
     * @param float $turn 1 or -1, which way it sweeps: 1 clockwise
     */
    private function ellipse(array $from, array $end, array $radii, float $side, float $turn): void
    {
        [$rx, $ry, $rotation] = $radii;
        [$cos, $sin] = [\cos($rotation), \sin($rotation)];
        [$hx, $hy] = [($from[0] - $end[0]) / 2, ($from[1] - $end[1]) / 2];
        [$x1, $y1] = [($cos * $hx) + ($sin * $hy), ($cos * $hy) - ($sin * $hx)];
        $scale = \sqrt(\max(1.0, (($x1 * $x1) / ($rx * $rx)) + (($y1 * $y1) / ($ry * $ry))));
        [$rx, $ry] = [$rx * $scale, $ry * $scale];
        $squares = ($rx * $rx * $y1 * $y1) + ($ry * $ry * $x1 * $x1);
        $root = \sqrt(\max(0.0, (($rx * $rx * $ry * $ry) - $squares) / $squares)) * $side;
        [$cx1, $cy1] = [($root * $rx * $y1) / $ry, (-$root * $ry * $x1) / $rx];
        $centre = [($cos * $cx1) - ($sin * $cy1) + (($from[0] + $end[0]) / 2), ($sin * $cx1) + ($cos * $cy1) + (($from[1] + $end[1]) / 2)];
        $first = \atan2(($y1 - $cy1) / $ry, ($x1 - $cx1) / $rx);
        $delta = \atan2((-$y1 - $cy1) / $ry, (-$x1 - $cx1) / $rx) - $first;
        // Turned the way its sweep flag says.
        $delta += match (true) {
            $turn > 0 && $delta < 0 => 2 * \M_PI,
            $turn < 0 && $delta > 0 => -2 * \M_PI,
            default => 0.0,
        };
        for ($step = 1; $step < self::STEPS; ++$step) {
            $angle = $first + (($delta * $step) / self::STEPS);
            [$ex, $ey] = [$rx * \cos($angle), $ry * \sin($angle)];
            $this->points[] = [($cos * $ex) - ($sin * $ey) + $centre[0], ($sin * $ex) + ($cos * $ey) + $centre[1]];
        }
    }

    /**
     * @param array{float, float} $origin
     * @return array{float, float}
     */
    private function point(array $origin): array
    {
        return [$origin[0] + $this->number(), $origin[1] + $this->number()];
    }

    /** The next command's letter, or the last one's again where numbers follow; null at the end. */
    private function command(string $last): ?string
    {
        $this->skip();
        if ($this->at >= \strlen($this->data)) {
            return null;
        }
        $char = $this->data[$this->at];
        if (\ctype_alpha($char)) {
            ++$this->at;

            return $char;
        }

        return '' === $last ? null : $last;
    }

    private function number(): float
    {
        $this->skip();
        $match = [];
        if (1 !== \preg_match('/\G[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $this->data, $match, offset: $this->at)) {
            $this->at = \strlen($this->data);

            return 0.0;
        }
        $this->at += \strlen($match[0]);

        return \floatval($match[0]);
    }

    /** An arc's flag, a single `0` or `1` that may run into what follows. */
    private function flag(): bool
    {
        $this->skip();
        $flag = '1' === ($this->data[$this->at] ?? '0');
        ++$this->at;

        return $flag;
    }

    private function skip(): void
    {
        $length = \strlen($this->data);
        while ($this->at < $length && \in_array($this->data[$this->at], [' ', ',', "\n", "\t", "\r"], strict: true)) {
            ++$this->at;
        }
    }
}
