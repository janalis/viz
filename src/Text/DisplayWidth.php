<?php

declare(strict_types=1);

namespace Janalis\Viz\Text;

use Symfony\Component\String\CodePointString;

/**
 * Monospace columns a label takes, the unit every native layout measures in.
 *
 * The measure is symfony/tui's `AnsiUtils::visibleWidth()`: a terminal renders
 * these layouts cell by cell, so a label measured any other way would be drawn
 * one column off. Escape sequences take no column and a tab takes three.
 */
final class DisplayWidth
{
    private const int TAB_WIDTH = 3;

    private const string ESCAPES = '/\x1b(?:\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]|[P\]_\^X][^\x07\x1b]*(?:\x07|\x1b\\\\)|[\x20-\x2F]+[\x30-\x7E]|[\x30-\x7E])/';

    /**
     * What mb_strwidth() does not measure the way a terminal draws it: a combining
     * mark, a format character, a Hangul vowel or final jamo, a skin tone modifier.
     */
    /** Any byte that does not start a well-formed UTF-8 sequence, which is dropped the way iconv's IGNORE drops it. */
    private const string INVALID_UTF8 = '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s';

    private const string MISMEASURED = '/[\t\p{M}\p{Cf}\x{1160}-\x{11FF}\x{D7B0}-\x{D7FF}\x{1F3FB}-\x{1F3FF}]/u';

    public static function of(string $text): int
    {
        if (1 === \preg_match('/^[\x20-\x7E]*$/', $text)) {
            return \strlen($text);
        }
        $clean = self::clean($text);
        if (1 !== \preg_match(self::MISMEASURED, $clean)) {
            return \mb_strwidth($clean, encoding: 'UTF-8');
        }
        $width = 0;
        foreach (self::graphemes($clean) as $grapheme) {
            $width += self::grapheme($grapheme);
        }

        return $width;
    }

    /**
     * The longest prefix of $text at most $width columns wide. A glyph that would
     * straddle the limit is left out whole.
     */
    public static function prefix(string $text, int $width): string
    {
        $prefix = '';
        $used = 0;
        // The text's own graphemes, so the prefix is a byte prefix of $text: a tab stays a tab.
        foreach (self::graphemes($text) as $grapheme) {
            $used += self::of($grapheme);
            if ($used > $width) {
                break;
            }
            $prefix .= $grapheme;
        }

        return $prefix;
    }

    /**
     * Words joined back into lines at most $width columns wide; a word wider than
     * a whole line is cut. Empty input is one empty line.
     *
     * @return non-empty-list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (\explode(' ', $text) as $word) {
            $candidate = '' === $line ? $word : $line.' '.$word;
            if (self::of($candidate) <= $width) {
                $line = $candidate;
                continue;
            }
            if ('' !== $line) {
                $lines[] = $line;
            }
            while (self::of($word) > $width) {
                $head = self::prefix($word, $width);
                if ('' === $head) {
                    break; // a single glyph wider than the whole line
                }
                $lines[] = $head;
                $word = \substr($word, \strlen($head));
            }
            $line = $word;
        }
        $lines[] = $line;

        return $lines;
    }

    private static function clean(string $text): string
    {
        $clean = \str_replace("\t", \str_repeat(' ', times: self::TAB_WIDTH), $text);
        $clean = \preg_replace(self::ESCAPES, replacement: '', subject: $clean) ?? $clean;

        return 1 === \preg_match('//u', $clean) ? $clean : \preg_replace(self::INVALID_UTF8, replacement: '', subject: $clean) ?? '';
    }

    private static function grapheme(string $grapheme): int
    {
        if (1 === \mb_strlen($grapheme, encoding: 'UTF-8') && 1 !== \preg_match(self::MISMEASURED, $grapheme)) {
            return \mb_strwidth($grapheme, encoding: 'UTF-8');
        }

        return new CodePointString($grapheme)->width(false);
    }

    /** @return list<string> */
    private static function graphemes(string $text): array
    {
        /** @var false|list<string> $graphemes */
        $graphemes = \grapheme_str_split($text);

        return false === $graphemes ? [] : $graphemes;
    }
}
