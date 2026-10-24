<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * Text measured as PlantUML measures it in its default font, and in Java's serif and
 * monospaced fonts its Creole sets text in, in each face, from the advances
 * tools/measure-plantuml-font.php took from the jar: to lay it out and wrap it where PlantUML
 * does, each character of the bundled font drawn in the middle of its own room. A character
 * not measured counts as an `n`, a wide one as its whole size.
 */
final class PlantUmlMetrics
{
    /** @var ?array<string, array<string, array<string, float>>> each family's advances, by face, by character */
    private static ?array $advances = null;

    public static function width(string $text, float $size, FontFace $face = FontFace::Regular, string $family = 'sans'): float
    {
        return \array_sum(self::slots($text, $size, $face, $family));
    }

    /**
     * Each character's advance, as PlantUML measures it: the room it takes along its line.
     *
     * @return list<float>
     */
    public static function slots(string $text, float $size, FontFace $face = FontFace::Regular, string $family = 'sans'): array
    {
        $families = self::advances();
        $faces = $families[$family] ?? $families['sans'];
        $advances =
            $faces[match ($face) {
                FontFace::Bold => 'bold',
                FontFace::Italic => 'italic',
                FontFace::BoldItalic => 'bolditalic',
                default => 'plain',
            }] ?? $faces['plain'];

        // A wide character not measured, as an ideograph, takes its whole size, as Java sets it.
        return \array_map(static fn (string $char): float => ($advances[$char] ?? (2 === DisplayWidth::of($char) ? 1.0 : $advances['n'] ?? 0.55)) * $size, \mb_str_split($text));
    }

    /** @return array<string, array<string, array<string, float>>> */
    private static function advances(): array
    {
        if (null === self::$advances) {
            /** @var array{advances: array<string, array<string, float>>, families?: array<string, array<string, array<string, float>>>} $font */
            $font = \json_decode((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/font.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$advances = ['sans' => $font['advances'], ...($font['families'] ?? [])];
        }

        return self::$advances;
    }
}
