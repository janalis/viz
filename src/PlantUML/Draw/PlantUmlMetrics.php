<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Text\FontFace;

/**
 * Text measured as PlantUML measures it in its default font, plain or bold, from the advances
 * tools/measure-plantuml-font.php took from the jar: to lay it out and wrap it where PlantUML
 * does, each character of the bundled font drawn in the middle of its own room. A character
 * not measured counts as an `n`.
 */
final class PlantUmlMetrics
{
    /** @var ?array{plain: array<string, float>, bold: array<string, float>} */
    private static ?array $advances = null;

    public static function width(string $text, float $size, FontFace $face = FontFace::Regular): float
    {
        $advances = self::advances()[FontFace::Bold === $face || FontFace::BoldItalic === $face ? 'bold' : 'plain'];
        $width = 0.0;
        foreach (\mb_str_split($text) as $char) {
            $width += $advances[$char] ?? $advances['n'] ?? 0.55;
        }

        return $width * $size;
    }

    /**
     * Each character's advance, as PlantUML measures it: the room it takes along its line.
     *
     * @return list<float>
     */
    public static function slots(string $text, float $size, FontFace $face = FontFace::Regular): array
    {
        $advances = self::advances()[FontFace::Bold === $face || FontFace::BoldItalic === $face ? 'bold' : 'plain'];

        return \array_map(static fn (string $char): float => ($advances[$char] ?? $advances['n'] ?? 0.55) * $size, \mb_str_split($text));
    }

    /** @return array{plain: array<string, float>, bold: array<string, float>} */
    private static function advances(): array
    {
        if (null === self::$advances) {
            /** @var array{advances: array{plain: array<string, float>, bold: array<string, float>}} $font */
            $font = \json_decode((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/font.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$advances = $font['advances'];
        }

        return self::$advances;
    }
}
