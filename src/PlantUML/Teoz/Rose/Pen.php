<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/** A stroke — its colour, width and dashes — drawing lines and outlined shapes in points. */
final readonly class Pen
{
    /** @param ?list<float> $dashes */
    public function __construct(
        public string $color = '#181818',
        public float $width = 1.0,
        public ?array $dashes = null,
    ) {}

    /** A style's line: its colour, thickness and dashes. */
    public static function of(Style $style, string $color = '#181818'): self
    {
        return new self($style->color('LineColor', $color), $style->number('LineThickness', 1.0), self::dashes($style->value('LineStyle')));
    }

    /**
     * A line style's dashes as PlantUML reads them: a dash and the gap after it, split by `-`,
     * `;` or `,`, the gap as long as the dash when not given; none for a dash of nothing.
     *
     * @return ?list<float>
     */
    public static function dashes(string $style): ?array
    {
        $parts = \array_values(\array_filter(\array_map(\trim(...), \explode(',', \strtr($style, from: '-;', to: ',,'))), static fn (string $part): bool => '' !== $part));
        if ([] === $parts || !\is_numeric($parts[0]) || 0.0 === (float) $parts[0]) {
            return null;
        }
        $dash = (float) $parts[0];

        return [$dash, \is_numeric($parts[1] ?? null) ? (float) $parts[1] : $dash];
    }

    public function withColor(string $color): self
    {
        return new self($color, $this->width, $this->dashes);
    }

    public function withWidth(float $width): self
    {
        return new self($this->color, $width, $this->dashes);
    }

    /** @param ?list<float> $dashes */
    public function withDashes(?array $dashes): self
    {
        return new self($this->color, $this->width, $dashes);
    }

    public function line(float $x1, float $y1, float $x2, float $y2): Mark
    {
        return $this->path([[$x1, $y1], [$x2, $y2]]);
    }

    /** @param list<array{float, float}> $points */
    public function path(array $points): Mark
    {
        return new Mark(MarkKind::Polyline, $points, stroke: $this->color, dashed: null !== $this->dashes, strokeWidth: $this->width, dashes: $this->dashes);
    }

    /** @param list<array{float, float}> $points a closed outline, filled */
    public function shape(array $points, string $fill): Mark
    {
        return new Mark(
            MarkKind::Polygon,
            $points,
            stroke: 0.0 === $this->width ? 'none' : $this->color,
            fill: $fill,
            dashed: null !== $this->dashes,
            strokeWidth: $this->width,
            dashes: $this->dashes,
        );
    }

    /** A rectangle, its corners rounded by a diameter. */
    public function rectangle(float $x, float $y, float $width, float $height, string $fill, float $round = 0.0): Mark
    {
        return $this->shape($round > 0 ? self::rounded($x, $y, $width, $height, $round / 2) : [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]], $fill);
    }

    public function ellipse(float $x, float $y, float $width, float $height, string $fill): Mark
    {
        $points = [];
        for ($step = 0; $step < 48; ++$step) {
            $angle = (2 * \M_PI * $step) / 48;
            $points[] = [$x + ($width / 2) + (($width / 2) * \cos($angle)), $y + ($height / 2) + (($height / 2) * \sin($angle))];
        }

        return $this->shape($points, $fill);
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
