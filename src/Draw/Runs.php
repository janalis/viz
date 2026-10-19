<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/** A styled label line as text marks: each run placed after the previous one, every bundled face sharing one advance. */
final class Runs
{
    /**
     * One line centered on `$x`.
     *
     * @param list<array{string, FontFace}> $runs
     * @return list<Mark>
     */
    public static function centered(array $runs, float $x, float $y, string $color, float $fontScale): array
    {
        if (1 === \count($runs)) {
            return [new Mark(MarkKind::Text, [[$x, $y]], $runs[0][0], stroke: $color, font: $runs[0][1], fontScale: $fontScale)];
        }

        return self::from($runs, $x - ((self::width($runs) * $fontScale) / 2), $y, $color, $fontScale);
    }

    /**
     * One line starting at `$left`.
     *
     * @param list<array{string, FontFace}> $runs
     * @return list<Mark>
     */
    public static function from(array $runs, float $left, float $y, string $color, float $fontScale): array
    {
        $marks = [];
        foreach ($runs as [$text, $font]) {
            // Drawn text drops edge spaces, so each run is centered on what it shows.
            $lead = \strlen($text) - \strlen(\ltrim($text, characters: ' '));
            $shown = \trim($text, characters: ' ');
            if ('' !== $shown) {
                $marks[] = new Mark(MarkKind::Text, [[$left + (($lead + (DisplayWidth::of($shown) / 2)) * $fontScale), $y]], $shown, stroke: $color, font: $font, fontScale: $fontScale);
            }
            $left += DisplayWidth::of($text) * $fontScale;
        }

        return $marks;
    }

    /** @param list<array{string, FontFace}> $runs */
    private static function width(array $runs): int
    {
        return \array_sum(\array_map(static fn (array $run): int => DisplayWidth::of($run[0]), $runs));
    }
}
