<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/**
 * The parts of a TrueType (`glyf` outline) font a PDF writer needs: its metrics, its advance
 * widths and its character map. CFF-flavoured OpenType and collections are refused, since a
 * `FontFile2` stream can only carry a single TrueType face.
 */
final readonly class TrueTypeFont
{
    /** Tables a face cannot be embedded or measured without. */
    private const array REQUIRED = ['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'glyf', 'loca'];

    /** @param array{int, int, int, int} $box */
    private function __construct(
        public string $bytes,
        public string $name,
        public int $unitsPerEm,
        public array $box,
        public int $ascent,
        public int $descent,
        public int $capHeight,
        public float $italicAngle,
        public bool $fixedPitch,
        public int $weight,
        private int $glyphs,
        private int $metrics,
        private int $widths,
        private int $format,
        private string $cmap,
    ) {}

    public static function file(string $path): ?self
    {
        $bytes = \is_file($path) && \is_readable($path) ? \file_get_contents($path) : false;

        return false === $bytes ? null : self::parse($bytes);
    }

    /** The face, or null when the bytes are not one complete TrueType font. */
    public static function parse(string $bytes): ?self
    {
        if (!\in_array(\substr($bytes, offset: 0, length: 4), ["\x00\x01\x00\x00", 'true'], strict: true)) {
            return null;
        }
        $tables = [];
        $count = self::short($bytes, 4);
        for ($index = 0; $index < $count; ++$index) {
            $record = 12 + (16 * $index);
            $offset = self::long($bytes, $record + 8);
            // A table running past the end of the file is as good as absent.
            if (($offset + self::long($bytes, $record + 12)) <= \strlen($bytes)) {
                $tables[\substr($bytes, $record, length: 4)] = $offset;
            }
        }
        if ([] !== \array_diff(self::REQUIRED, \array_keys($tables))) {
            return null;
        }
        [$head, $hhea] = [$tables['head'], $tables['hhea']];
        $unitsPerEm = self::short($bytes, $head + 18);
        $metrics = self::short($bytes, $hhea + 34);
        if (0 === $unitsPerEm || 0 === $metrics) {
            return null;
        }
        $ascent = self::signed($bytes, $hhea + 4);
        $os2 = $tables['OS/2'] ?? null;
        $post = $tables['post'] ?? null;
        [$format, $cmap] = self::cmap($bytes, $tables['cmap']);

        return new self(
            $bytes,
            \array_key_exists('name', $tables) ? self::postScriptName($bytes, $tables['name']) : 'Embedded',
            $unitsPerEm,
            [self::signed($bytes, $head + 36), self::signed($bytes, $head + 38), self::signed($bytes, $head + 40), self::signed($bytes, $head + 42)],
            $ascent,
            self::signed($bytes, $hhea + 6),
            null !== $os2 && self::short($bytes, $os2) >= 2 ? self::signed($bytes, $os2 + 88) : $ascent,
            null === $post ? 0.0 : self::signedLong($bytes, $post + 4) / 65_536,
            null !== $post && 0 !== self::long($bytes, $post + 12),
            null === $os2 ? 400 : self::short($bytes, $os2 + 4),
            self::short($bytes, $tables['maxp'] + 4),
            $metrics,
            $tables['hmtx'],
            $format,
            $cmap,
        );
    }

    /** The glyph drawing a character, or 0 (`.notdef`) where the face has none. */
    public function glyph(int $codepoint): int
    {
        $glyph = match ($this->format) {
            12 => $this->grouped($codepoint),
            4 => $codepoint <= 0xFFFF ? $this->segmented($codepoint) : 0,
            default => 0,
        };

        return $glyph < $this->glyphs ? $glyph : 0;
    }

    /** A glyph's advance in font units; glyphs past the metrics share the last advance. */
    public function advance(int $glyph): int
    {
        return self::short($this->bytes, $this->widths + (4 * \min($glyph, $this->metrics - 1)));
    }

    /**
     * The best Unicode subtable: the full repertoire (format 12) before the Basic Multilingual
     * Plane (format 4).
     *
     * @return array{int, string}
     */
    private static function cmap(string $bytes, int $cmap): array
    {
        $best = [0, 0];
        $count = self::short($bytes, $cmap + 2);
        for ($index = 0; $index < $count; ++$index) {
            $record = $cmap + 4 + (8 * $index);
            $platform = self::short($bytes, $record);
            $unicode = 0 === $platform || (3 === $platform && \in_array(self::short($bytes, $record + 2), [1, 10], strict: true));
            $offset = $cmap + self::long($bytes, $record + 4);
            $format = self::short($bytes, $offset);
            if ($unicode && $format > $best[0] && \in_array($format, [4, 12], strict: true)) {
                $best = [$format, $offset];
            }
        }
        [$format, $offset] = $best;
        $length = 12 === $format ? self::long($bytes, $offset + 4) : self::short($bytes, $offset + 2);

        return [$format, \substr($bytes, $offset, $length)];
    }

    private function grouped(int $codepoint): int
    {
        [$low, $high] = [0, self::long($this->cmap, 12) - 1];
        while ($low <= $high) {
            $middle = \intdiv(num1: $low + $high, num2: 2);
            $group = 16 + (12 * $middle);
            $start = self::long($this->cmap, $group);
            if ($codepoint < $start) {
                $high = $middle - 1;
                continue;
            }
            if ($codepoint > self::long($this->cmap, $group + 4)) {
                $low = $middle + 1;
                continue;
            }

            return self::long($this->cmap, $group + 8) + ($codepoint - $start);
        }

        return 0;
    }

    private function segmented(int $codepoint): int
    {
        $segments = \intdiv(num1: self::short($this->cmap, 6), num2: 2);
        $index = 0;
        while ($index < $segments && $codepoint > self::short($this->cmap, 14 + (2 * $index))) {
            ++$index;
        }
        $start = self::short($this->cmap, 16 + (2 * ($segments + $index)));
        if ($index >= $segments || $codepoint < $start) {
            return 0;
        }
        $delta = self::short($this->cmap, 16 + (2 * ((2 * $segments) + $index)));
        $rangeAt = 16 + (2 * ((3 * $segments) + $index));
        $range = self::short($this->cmap, $rangeAt);
        if (0 === $range) {
            return ($codepoint + $delta) & 0xFFFF;
        }
        $glyph = self::short($this->cmap, $rangeAt + $range + (2 * ($codepoint - $start)));

        return 0 === $glyph ? 0 : ($glyph + $delta) & 0xFFFF;
    }

    /**
     * The PostScript name (name ID 6), reduced to characters a PDF name carries verbatim.
     * PostScript names are ASCII, so dropping every other byte also decodes the UTF-16 records.
     */
    private static function postScriptName(string $bytes, int $table): string
    {
        $count = self::short($bytes, $table + 2);
        for ($index = 0; $index < $count; ++$index) {
            $record = $table + 6 + (12 * $index);
            if (6 !== self::short($bytes, $record + 6)) {
                continue;
            }
            $raw = \substr($bytes, $table + self::short($bytes, $table + 4) + self::short($bytes, $record + 10), self::short($bytes, $record + 8));
            $name = (string) \preg_replace('/[^A-Za-z0-9_-]/', replacement: '', subject: $raw);
            if ('' !== $name) {
                return $name;
            }
        }

        return 'Embedded';
    }

    private static function short(string $bytes, int $offset): int
    {
        $value = $offset >= 0 && \strlen($bytes) >= ($offset + 2) ? \unpack('n', $bytes, $offset) : false;

        return false === $value ? 0 : (int) $value[1];
    }

    private static function signed(string $bytes, int $offset): int
    {
        $value = self::short($bytes, $offset);

        return $value >= 0x8000 ? $value - 0x1_0000 : $value;
    }

    private static function long(string $bytes, int $offset): int
    {
        $value = $offset >= 0 && \strlen($bytes) >= ($offset + 4) ? \unpack('N', $bytes, $offset) : false;

        return false === $value ? 0 : (int) $value[1];
    }

    private static function signedLong(string $bytes, int $offset): int
    {
        $value = self::long($bytes, $offset);

        return $value >= 0x8000_0000 ? $value - 0x1_0000_0000 : $value;
    }
}
