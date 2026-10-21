<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Text\FontFace;

/**
 * Text measured as PlantUML measures it in its default font, plain or bold, from the advances
 * tools/measure-plantuml-font.php took from the jar: to wrap text where PlantUML wraps it,
 * though the bundled font draws it wider. A character not measured counts as an `n`.
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
