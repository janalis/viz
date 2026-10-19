<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Pdf\TrueTypeFont;
use Janalis\Viz\Text\Font;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

/** TrueType metrics and character maps, read from the bundled faces and from synthetic fonts. */
final class TrueTypeFontTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            \unlink($file);
        }
    }

    public function testTheBundledFacesReadTheirMetrics(): void
    {
        $regular = TrueTypeFont::file(Font::FILE);
        $this->assertNotNull($regular);
        $this->assertSame('IBMPlexMono-Regular', $regular->name);
        $this->assertSame(1000, $regular->unitsPerEm);
        $this->assertSame([-260, -350, 648, 1119], $regular->box);
        $this->assertSame(1025, $regular->ascent);
        $this->assertSame(-275, $regular->descent);
        $this->assertSame(698, $regular->capHeight);
        $this->assertSame(0.0, $regular->italicAngle);
        $this->assertTrue($regular->fixedPitch);
        $this->assertSame(400, $regular->weight);
        $this->assertSame((string) \file_get_contents(Font::FILE), $regular->bytes);
        $this->assertSame(30, $regular->glyph(0x41));
        $this->assertSame(31, $regular->glyph(0x42));
        $this->assertSame(600, $regular->advance($regular->glyph(0x41)));
        $this->assertNotSame(0, $regular->glyph(0xE9));
        $this->assertNotSame(0, $regular->glyph(0x2192));
        $this->assertSame(0, $regular->glyph(0x6F22));
        $this->assertSame(0, $regular->glyph(0x1_F60E));

        $italic = TrueTypeFont::file(Font::file(FontFace::BoldItalic));
        $this->assertNotNull($italic);
        $this->assertSame('IBMPlexMono-BoldItalic', $italic->name);
        $this->assertSame(-9.0, $italic->italicAngle);
        $this->assertSame(700, $italic->weight);
    }

    public function testUnreadableFilesAreNoFont(): void
    {
        $this->assertNull(TrueTypeFont::file('/nonexistent/font.ttf'));
        $this->assertNotNull(TrueTypeFont::file($this->file(self::font([[0x41, 0x41, 1]]))));
    }

    public function testOnlyCompleteTrueTypeOutlineFontsParse(): void
    {
        $tables = self::tables([[0x41, 0x41, 1]]);
        $this->assertNotNull(TrueTypeFont::parse(self::sfnt($tables)));
        $this->assertNotNull(TrueTypeFont::parse(self::sfnt($tables, version: 'true')), 'Apple TrueType');
        $this->assertNull(TrueTypeFont::parse(self::sfnt($tables, version: 'OTTO')), 'CFF outlines');
        $this->assertNull(TrueTypeFont::parse('ttcf'.\substr(self::sfnt($tables), offset: 4)), 'a collection');
        $this->assertNull(TrueTypeFont::parse(''));
        $this->assertNull(TrueTypeFont::parse("\x00\x01\x00\x00"), 'no table count');
        $this->assertNull(TrueTypeFont::parse("\x00\x01\x00\x00\x00"), 'half a table count');
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'glyf', 'loca'] as $required) {
            $missing = $tables;
            unset($missing[$required]);
            $this->assertNull(TrueTypeFont::parse(self::sfnt($missing)), $required.' is required');
        }
        $last = $tables;
        unset($last['maxp']);
        $font = self::sfnt([...$last, 'maxp' => $tables['maxp']]);
        $this->assertNotNull(TrueTypeFont::parse($font));
        $this->assertNull(TrueTypeFont::parse(\substr($font, offset: 0, length: -1)), 'the last table runs past the end');
        $this->assertNull(TrueTypeFont::parse(self::sfnt(['head' => self::put($tables['head'], 18, \pack('n', 0))] + $tables)), 'no units per em');
        $this->assertNull(TrueTypeFont::parse(self::sfnt(['hhea' => self::put($tables['hhea'], 34, \pack('n', 0))] + $tables)), 'no horizontal metrics');
    }

    public function testMetricsComeFromHeadHheaOs2AndPost(): void
    {
        $font = TrueTypeFont::parse(self::font([[0x41, 0x41, 1]]));
        $this->assertNotNull($font);
        $this->assertSame(2048, $font->unitsPerEm);
        $this->assertSame([-32_768, -300, 1900, 2000], $font->box);
        $this->assertSame(1800, $font->ascent);
        $this->assertSame(-400, $font->descent);
        $this->assertSame(1400, $font->capHeight);
        $this->assertSame(-12.5, $font->italicAngle);
        $this->assertFalse($font->fixedPitch);
        $this->assertSame(700, $font->weight);
        $this->assertSame('Synthetic-Face', $font->name);
    }

    public function testOptionalTablesHaveDefaults(): void
    {
        $tables = self::tables([[0x41, 0x41, 1]]);
        unset($tables['OS/2'], $tables['post'], $tables['name']);
        $font = TrueTypeFont::parse(self::sfnt($tables));
        $this->assertNotNull($font);
        $this->assertSame(1800, $font->capHeight, 'the ascent stands in for the cap height');
        $this->assertSame(400, $font->weight);
        $this->assertSame(0.0, $font->italicAngle);
        $this->assertFalse($font->fixedPitch);
        $this->assertSame('Embedded', $font->name);

        $tables = self::tables([[0x41, 0x41, 1]]);
        $tables['OS/2'] = self::put($tables['OS/2'], 0, \pack('n', 1));
        // Any non-zero value marks fixed pitch, even one with its low bytes clear.
        $tables['post'] = self::put(self::put($tables['post'], 12, \pack('N', 0x0100_0000)), 4, \pack('N', 0x8000_0000));
        $font = TrueTypeFont::parse(self::sfnt($tables));
        $this->assertNotNull($font);
        $this->assertSame(1800, $font->capHeight, 'OS/2 before version 2 has no cap height');
        $this->assertSame(700, $font->weight);
        $this->assertTrue($font->fixedPitch);
        $this->assertSame(-32_768.0, $font->italicAngle);
        $tables['post'] = self::put($tables['post'], 12, \pack('N', 1));
        $this->assertTrue(TrueTypeFont::parse(self::sfnt($tables))?->fixedPitch);
    }

    public function testThePostScriptNameIsTheFirstUsableNameIdSix(): void
    {
        $tables = self::tables([[0x41, 0x41, 1]]);
        $tables['name'] = self::names([
            [3, 4, 'Family'],
            [1, 6, "Mac (Name)/\xC3\xA9"],
            [3, 6, 'Other'],
        ]);
        $this->assertSame('MacName', TrueTypeFont::parse(self::sfnt($tables))?->name, 'Macintosh names are bytes');
        $tables['name'] = self::names([[0, 6, '()'], [3, 6, 'Windows']]);
        $this->assertSame('Windows', TrueTypeFont::parse(self::sfnt($tables))?->name, 'a name with nothing usable is passed over');
        $tables['name'] = self::names([[0, 6, 'Uni_code-1']]);
        $this->assertSame('Uni_code-1', TrueTypeFont::parse(self::sfnt($tables))?->name);
        $tables['name'] = self::names([[3, 1, 'Family']]);
        $this->assertSame('Embedded', TrueTypeFont::parse(self::sfnt($tables))?->name);
    }

    public function testGroupedMapsSearchEveryGroup(): void
    {
        $font = TrueTypeFont::parse(self::font([
            [0x20,     0x20,     1],
            [0x41,     0x43,     2],
            [0x1_F600, 0x1_F60F, 5],
            [0x2_0000, 0x2_0000, 40],
            [0x2_0001, 0x2_0001, 30],
            [0x2_0002, 0x2_0002, 29],
        ], glyphs: 30));
        $this->assertNotNull($font);
        $this->assertSame(1, $font->glyph(0x20));
        $this->assertSame(2, $font->glyph(0x41));
        $this->assertSame(4, $font->glyph(0x43));
        $this->assertSame(0, $font->glyph(0x44));
        $this->assertSame(0, $font->glyph(0x1F));
        $this->assertSame(5, $font->glyph(0x1_F600));
        $this->assertSame(19, $font->glyph(0x1_F60E));
        $this->assertSame(0, $font->glyph(0x1_F610));
        $this->assertSame(0, $font->glyph(0x2_0000), 'a glyph past the face count is missing');
        $this->assertSame(0, $font->glyph(0x2_0001));
        $this->assertSame(29, $font->glyph(0x2_0002));
    }

    public function testSegmentedMapsHonorDeltasAndGlyphIndexes(): void
    {
        $tables = self::tables([], glyphs: 100);
        $tables['cmap'] = self::cmap([[3, 1, self::segments()]]);
        $font = TrueTypeFont::parse(self::sfnt($tables));
        $this->assertNotNull($font);
        $this->assertSame(2, $font->glyph(0x41));
        $this->assertSame(4, $font->glyph(0x43));
        $this->assertSame(0, $font->glyph(0x44));
        $this->assertSame(0, $font->glyph(0x40), 'below a segment is missing, whatever its delta would give');
        $this->assertSame(12, $font->glyph(0x61), 'through the glyph array, plus the delta');
        $this->assertSame(0, $font->glyph(0x62), 'a zero in the glyph array stays missing');
        $this->assertSame(0, $font->glyph(0x1_0041), 'format 4 stops at the Basic Multilingual Plane');
        $this->assertSame(16, $font->glyph(0xFFFF));
        $this->assertSame(1, $font->glyph(0xFFF0), 'the delta wraps modulo 65536');
    }

    public function testTheFullRepertoireMapWinsOverTheBasicPlaneOne(): void
    {
        $tables = self::tables([]);
        $tables['cmap'] = self::cmap([[1, 0, self::grouped([[0x41, 0x41, 9]])], [3, 1, self::segments()], [3, 10, self::grouped([[0x41, 0x41, 7]])]]);
        $this->assertSame(7, TrueTypeFont::parse(self::sfnt($tables))?->glyph(0x41));
        $tables['cmap'] = self::cmap([[3, 10, self::grouped([[0x41, 0x41, 7]])], [3, 1, self::segments()]]);
        $this->assertSame(7, TrueTypeFont::parse(self::sfnt($tables))?->glyph(0x41), 'a later lesser format does not replace it');
        $tables['cmap'] = self::cmap([[1, 0, self::grouped([[0x41, 0x41, 9]])], [3, 0, self::grouped([[0x41, 0x41, 8]])]]);
        $this->assertSame(0, TrueTypeFont::parse(self::sfnt($tables))?->glyph(0x41), 'neither Macintosh nor symbol maps are Unicode');
        $tables['cmap'] = self::cmap([[0, 3, \pack('nnn', 6, 10, 0).\pack('nn', 0x41, 1)]]);
        $this->assertSame(0, TrueTypeFont::parse(self::sfnt($tables))?->glyph(0x41), 'other formats are not read');
        $tables['cmap'] = self::cmap([[0, 4, self::grouped([[0x41, 0x41, 5]])], [3, 10, self::grouped([[0x41, 0x41, 7]])]]);
        $this->assertSame(5, TrueTypeFont::parse(self::sfnt($tables))?->glyph(0x41), 'Unicode-platform maps count, and the first of a format wins');
    }

    public function testAdvancesPastTheMetricsRepeatTheLastOne(): void
    {
        $font = TrueTypeFont::parse(self::font([[0x41, 0x41, 1]], advances: [500, 600, 700]));
        $this->assertNotNull($font);
        $this->assertSame([500, 600, 700, 700, 700], \array_map($font->advance(...), [0, 1, 2, 3, 9]));
    }

    /**
     * A complete synthetic face: 2048 units per em, glyphs mapped by format 12 groups.
     *
     * @param list<array{int, int, int}> $groups
     * @param list<int> $advances
     */
    public static function font(array $groups, array $advances = [500, 600, 700], int $glyphs = 10): string
    {
        return self::sfnt(self::tables($groups, $advances, $glyphs));
    }

    /**
     * @param list<array{int, int, int}> $groups
     * @param list<int> $advances
     * @return array<string, string>
     */
    public static function tables(array $groups, array $advances = [500, 600, 700], int $glyphs = 10): array
    {
        $head = self::put(self::put(\str_repeat("\x00", times: 54), 18, \pack('n', 2048)), 36, \pack('n4', 0x8000, -300 & 0xFFFF, 1900, 2000));
        $hhea = self::put(self::put(\str_repeat("\x00", times: 36), 4, \pack('n2', 1800, -400 & 0xFFFF)), 34, \pack('n', \count($advances)));
        $hmtx = '';
        foreach ($advances as $advance) {
            $hmtx .= \pack('nn', $advance, 0);
        }
        $os2 = self::put(self::put(\str_repeat("\x00", times: 96), 0, \pack('n', 2)), 4, \pack('n', 700));
        $os2 = self::put($os2, 88, \pack('n', 1400));

        return [
            'OS/2' => $os2,
            'cmap' => self::cmap([[3, 10, self::grouped($groups)]]),
            'glyf' => '',
            'head' => $head,
            'hhea' => $hhea,
            'hmtx' => $hmtx,
            'loca' => '',
            'maxp' => \pack('Nn', 0x0001_0000, $glyphs),
            'name' => self::names([[3, 6, 'Synthetic-Face']]),
            'post' => self::put(\str_repeat("\x00", times: 32), 4, \pack('N', -819_200 & 0xFFFF_FFFF)),
        ];
    }

    /** @param array<string, string> $tables */
    public static function sfnt(array $tables, string $version = "\x00\x01\x00\x00"): string
    {
        $directory = $version.\pack('nnnn', \count($tables), 0, 0, 0);
        $offset = 12 + (16 * \count($tables));
        $data = '';
        foreach ($tables as $tag => $bytes) {
            $directory .= \str_pad($tag, length: 4).\pack('NNN', 0, $offset + \strlen($data), \strlen($bytes));
            $data .= $bytes;
        }

        return $directory.$data;
    }

    /** @param list<array{int, int, string}> $subtables platform, encoding and bytes */
    private static function cmap(array $subtables): string
    {
        $records = \pack('nn', 0, \count($subtables));
        $offset = 4 + (8 * \count($subtables));
        $data = '';
        foreach ($subtables as [$platform, $encoding, $bytes]) {
            $records .= \pack('nnN', $platform, $encoding, $offset + \strlen($data));
            $data .= $bytes;
        }

        return $records.$data;
    }

    /** @param list<array{int, int, int}> $groups */
    private static function grouped(array $groups): string
    {
        $table = \pack('nnNNN', 12, 0, 256, 0, \count($groups));
        foreach ($groups as [$start, $end, $glyph]) {
            $table .= \pack('NNN', $start, $end, $glyph);
        }

        // Padded to a whole 256 bytes, so a length read one byte off is plainly wrong.
        return \str_pad($table, length: 256, pad_string: "\x00");
    }

    /** 0x41–0x43 by delta, 0x61–0x62 through the glyph array (0x62 maps to zero), then 0xFFF0–0xFFFF wrapping. */
    private static function segments(): string
    {
        $ends = [0x43, 0x62, 0xFFFF];
        $starts = [0x41, 0x61, 0xFFF0];
        $deltas = [-0x3F, 5, 0x11];
        $count = \count($ends);
        $glyphs = \pack('nn', 7, 0);
        // The second segment's range offset points from its own slot to the glyph array.
        $ranges = [0, 2 * ($count - 1), 0];
        $table = \pack('nnnnnnn', 4, 256, 0, 2 * $count, 0, 0, 0);
        $table .= \pack('n*', ...$ends).\pack('n', 0).\pack('n*', ...$starts);
        $table .= \pack('n*', ...\array_map(static fn (int $delta): int => $delta & 0xFFFF, $deltas)).\pack('n*', ...$ranges).$glyphs;

        // Padded to a whole 256 bytes, so a length read one byte off is plainly wrong.
        return \str_pad($table, length: 256, pad_string: "\x00");
    }

    /** @param list<array{int, int, string}> $records platform, name id and text */
    private static function names(array $records): string
    {
        $header = '';
        $strings = '';
        foreach ($records as [$platform, $id, $text]) {
            $bytes = 1 === $platform ? $text : (string) \mb_convert_encoding($text, to_encoding: 'UTF-16BE', from_encoding: 'UTF-8');
            $header .= \pack('nnnnnn', $platform, 0, 0, $id, \strlen($bytes), \strlen($strings));
            $strings .= $bytes;
        }

        return \pack('nnn', 0, \count($records), 6 + (12 * \count($records))).$header.$strings;
    }

    private static function put(string $bytes, int $offset, string $value): string
    {
        return \substr_replace($bytes, $value, $offset, \strlen($value));
    }

    private function file(string $bytes): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), prefix: 'viz-ttf-');
        \file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
