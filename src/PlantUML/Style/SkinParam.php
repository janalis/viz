<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

/**
 * A `skinparam` as the style declarations PlantUML turns it into: by its table of which
 * styles each parameter sets, `Name<<stereotype>>` narrowing them to a stereotype, with its
 * spellings of shadows, dashes and compound `color;line.dashed;text:color` values.
 */
final class SkinParam
{
    /**
     * @param array<string, list<array{list<string>, string}>> $table each parameter's style names and property
     * @return list<array{StyleSignature, string, string}>
     */
    public static function declarations(string $key, string $value, array $table): array
    {
        $stereotypes = [];
        $groups = [];
        // As PlantUML's `cleanForKey`: each `<<…>>`, wherever it stands, taken out, and a key for each.
        if ((int) \preg_match_all('/<<(.*?)>>/', $key, $groups) >= 1) {
            $key = (string) \preg_replace('/<<(.*?)>>/', replacement: '', subject: $key);
            if (\count($groups[1]) > 1) {
                return \array_values(\array_merge(...\array_map(static fn (string $stereotype): array => self::declarations($key.'<<'.$stereotype.'>>', $value, $table), $groups[1])));
            }
            $stereotypes = \array_values(\array_filter(\array_map(\trim(...), \explode('&', $groups[1][0])), static fn (string $stereotype): bool => '' !== $stereotype));
        }
        $lower = \strtolower($key);
        $value = self::spelled($lower, $value);
        $targets = $table[$lower] ?? null;
        if (null === $targets) {
            $shadow = match ($lower) {
                'shadowing' => ['root'],
                'noteshadowing' => ['root', 'note'],
                default => null,
            };

            return null === $shadow ? [] : [[StyleSignature::of($shadow, $stereotypes), 'Shadowing', self::shadow($value)]];
        }
        $declarations = [];
        $plain = $value;
        if (\str_contains($value, ';') || \str_starts_with($value, 'text:')) {
            // As a string tokenizer reads it: empty parts dropped, a leading empty value kept as a space.
            $parts = \array_values(\array_filter(\explode(';', \str_starts_with($value, ';') ? ' '.$value : $value), static fn (string $part): bool => '' !== $part));
            $plain = \str_contains($value, ';') ? $parts[0] : ' ';
            foreach (\str_contains($value, ';') ? \array_slice($parts, offset: 1) : $parts as $part) {
                foreach ($targets as [$names]) {
                    $declarations = [...$declarations, ...self::compound($part, StyleSignature::of($names, $stereotypes))];
                }
            }
        }
        if (' ' !== $plain && '' !== $plain) {
            foreach ($targets as [$names, $property]) {
                $declarations[] = [StyleSignature::of($names, $stereotypes), $property, $plain];
            }
        }

        return $declarations;
    }

    private static function spelled(string $key, string $value): string
    {
        if (\str_ends_with($key, 'shadowing')) {
            $value = match (\strtolower($value)) {
                'false' => '0',
                'true' => '3',
                default => $value,
            };
        }
        if ('hyperlinkunderline' === $key) {
            $value = match (\strtolower($value)) {
                'false' => '0',
                'true' => '1',
                default => $value,
            };
        }

        return match (\strtolower($value)) {
            'right:right' => 'right',
            'dotted' => '1;3',
            'dashed' => '7;7',
            default => $value,
        };
    }

    /** @return list<array{StyleSignature, string, string}> */
    private static function compound(string $part, StyleSignature $signature): array
    {
        return match (true) {
            \str_starts_with($part, 'text:') => [[$signature, 'FontColor', \explode(':', $part)[1]]],
            \str_starts_with($part, 'line.dotted') => [[$signature, 'LineStyle', '1;3']],
            \str_starts_with($part, 'line.dashed') => [[$signature, 'LineStyle', '7;7']],
            \str_contains(\strtolower($part), 'bold') => [[$signature, 'LineThickness', '2']],
            default => [],
        };
    }

    private static function shadow(string $value): string
    {
        return match (\strtolower($value)) {
            'false', 'no' => '0',
            'true', 'yes' => '3',
            default => $value,
        };
    }
}
