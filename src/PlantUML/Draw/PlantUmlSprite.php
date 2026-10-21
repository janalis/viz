<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Source\Unsupported;

/**
 * A sprite as PlantUML's `SpriteMonochrome` keeps one: a grid of gray levels, 0 to 3, 7 or 15,
 * read from the encodings `sprite` writes — a hex digit a pixel for 16 levels, a character
 * for two or three pixels down for 8 or 4, or levels deflated and encoded — and drawn as
 * PlantUML draws it: each level a shade between the background and the colour, the faintest
 * fading to transparent.
 */
final readonly class PlantUmlSprite
{
    /** @param list<list<int>> $gray each row's levels */
    private function __construct(
        public int $width,
        public int $height,
        private int $levels,
        private array $gray,
    ) {}

    /**
     * 16 levels, a hex digit a pixel: as wide as the first row, as tall as the rows.
     *
     * @param list<string> $rows
     */
    public static function gray16(array $rows): self
    {
        $width = \strlen($rows[0] ?? '');
        $gray = [];
        foreach ($rows as $row) {
            $levels = [];
            for ($x = 0; $x < $width; ++$x) {
                $char = $row[$x] ?? '0';
                if (!\ctype_xdigit($char)) {
                    throw new Unsupported('The PlantUML sprite has a pixel that is no hex digit: '.$row);
                }
                $levels[] = (int) \hexdec($char);
            }
            $gray[] = $levels;
        }

        return new self($width, \count($rows), 16, $gray);
    }

    /**
     * 8 or 4 levels, a character of PlantUML's 6-bit alphabet for two or three pixels down.
     *
     * @param list<string> $rows
     */
    public static function packed(int $levels, int $width, int $height, array $rows): self
    {
        $down = 8 === $levels ? 2 : 3;
        $gray = \array_fill(0, \max(0, $height), \array_fill(0, \max(0, $width), value: 0));
        foreach ($rows as $line => $row) {
            for ($x = 0, $length = \min($width, \strlen($row)); $x < $length; ++$x) {
                $value = self::decode6bit($row[$x]);
                foreach (self::digits($value, $levels, $down) as $offset => $level) {
                    $y = ($line * $down) + $offset;
                    if ($y < $height) {
                        $gray[$y][$x] = $level;
                    }
                }
            }
        }

        return new self($width, $height, $levels, $gray);
    }

    /** Levels a pixel each, deflated and written in PlantUML's 6-bit alphabet. */
    public static function compressed(int $levels, int $width, int $height, string $data): self
    {
        $bytes = '';
        foreach (\str_split($data.\str_repeat('0', times: (4 - (\strlen($data) % 4)) % 4), length: 4) as $quad) {
            [$a, $b, $c, $d] = \array_map(self::decode6bit(...), \str_split($quad));
            $bytes .= \chr((($a << 2) | ($b >> 4)) & 0xFF).\chr((($b << 4) | ($c >> 2)) & 0xFF).\chr((($c << 6) | $d) & 0xFF);
        }
        // Without zlib, a sprite deflated cannot be read either; PlantUML can.
        \set_error_handler(static fn (): bool => true);
        try {
            $raw = \function_exists('gzinflate') ? \gzinflate($bytes) : false;
        } finally {
            \restore_error_handler();
        }
        if (false === $raw || \strlen($raw) < ($width * $height)) {
            throw new Unsupported('The PlantUML sprite cannot be decoded');
        }
        $gray = [];
        for ($y = 0; $y < $height; ++$y) {
            $gray[] = \array_map(static fn (string $byte): int => \min($levels - 1, \ord($byte)), \str_split(\substr($raw, $y * $width, $width)));
        }

        return new self($width, $height, $levels, $gray);
    }

    /**
     * As PlantUML's `toUImage`: each level a shade from the background to the colour, opaque
     * but for the levels within the faintest quarter of the brightest, which fade out.
     *
     * @param string $background as `#rrggbb`
     * @param string $color as `#rrggbb`
     */
    public function png(string $background, string $color): string
    {
        [$back, $front] = [self::rgb($background), self::rgb($color)];
        $max = 0.0;
        foreach ($this->gray as $row) {
            foreach ($row as $level) {
                $max = \max($max, $level / ($this->levels - 1));
            }
        }
        $pixels = '';
        foreach ($this->gray as $row) {
            $pixels .= "\0";
            foreach ($row as $level) {
                $coef = $level / ($this->levels - 1);
                $alpha = $coef > ($max / 4) ? 255 : (int) (255 * (($coef * 4) / ($max > 0 ? $max : 1)));
                foreach ([0, 1, 2] as $channel) {
                    $pixels .= \chr((int) \round($back[$channel] + (($front[$channel] - $back[$channel]) * $coef)));
                }
                $pixels .= \chr($alpha);
            }
        }

        return 'data:image/png;base64,'.\base64_encode(self::encoded($this->width, $this->height, $pixels));
    }

    /** A PNG of 8-bit RGBA rows, its data in stored deflate blocks, needing no zlib. */
    private static function encoded(int $width, int $height, string $pixels): string
    {
        $stored = '';
        $blocks = \str_split($pixels, length: 65_535);
        foreach ($blocks as $index => $block) {
            $final = $index === (\count($blocks) - 1) ? 1 : 0;
            $stored .= \chr($final).\pack('v', \strlen($block)).\pack('v', \strlen($block) ^ 0xFFFF).$block;
        }
        $adler = \hash('adler32', $pixels, binary: true);
        $chunk = static fn (string $type, string $data): string => \pack('N', \strlen($data)).$type.$data.\pack('N', \crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', \pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', "\x78\x01".$stored.$adler).$chunk('IEND', data: '');
    }

    /** @return array{int, int, int} a colour of six hex digits, as PlantUML's colours are written here */
    private static function rgb(string $hex): array
    {
        return [(int) \hexdec(\substr($hex, offset: 1, length: 2)), (int) \hexdec(\substr($hex, offset: 3, length: 2)), (int) \hexdec(\substr($hex, offset: 5, length: 2))];
    }

    /** @return list<int> a value's digits in a base, the first the highest */
    private static function digits(int $value, int $base, int $count): array
    {
        $digits = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $digits[] = \intdiv($value, $base ** $i) % $base;
        }

        return $digits;
    }

    /** As PlantUML's `AsciiEncoder`: digits, upper case, lower case, `-` and `_` for 0 to 63. */
    private static function decode6bit(string $char): int
    {
        $position = \strpos('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_', $char);

        return false === $position ? 0 : $position;
    }
}
