<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Text;

use Janalis\Viz\Text\DisplayWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisplayWidthTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function widths(): iterable
    {
        yield 'ascii' => ['Hello', 5];
        yield 'empty' => ['', 0];
        yield 'wide' => ['日本', 4];
        yield 'accented' => ['café', 4];
        yield 'combining mark' => ["e\u{0301}", 1];
        yield 'zero-width joiner family' => ["👨\u{200D}👩\u{200D}👧", 2];
        yield 'skin tone' => ["👍\u{1F3FD}", 2];
        yield 'hangul jamo' => ["\u{1100}\u{1161}\u{11A8}", 2];
        yield 'tab' => ["a\tb", 5];
        yield 'escape sequence' => ["\e[31mred\e[0m", 3];
        yield 'osc hyperlink' => ["\e]8;;https://x\e\\link\e]8;;\e\\", 4];
        yield 'invalid utf-8' => ["a\xFFb", 2];
    }

    #[DataProvider('widths')]
    public function testTextTakesTheColumnsATerminalDrawsItIn(string $text, int $width): void
    {
        $this->assertSame($width, DisplayWidth::of($text));
    }

    public function testAPrefixNeverSplitsAWideGlyph(): void
    {
        $this->assertSame('日', DisplayWidth::prefix('日本', width: 3));
        $this->assertSame('', DisplayWidth::prefix('日本', width: 1));
        $this->assertSame("e\u{0301}x", DisplayWidth::prefix("e\u{0301}xy", width: 2));
        $this->assertSame('abc', DisplayWidth::prefix('abc', width: 10));
    }

    public function testWrappingBreaksBetweenWordsAndCutsAWordWiderThanTheLine(): void
    {
        $this->assertSame(['one two', 'three'], DisplayWidth::wrap('one two three', width: 7));
        $this->assertSame(['abcd', 'efgh', 'ij'], DisplayWidth::wrap('abcdefghij', width: 4));
        $this->assertSame(['ab', '日', '本'], DisplayWidth::wrap('ab 日本', width: 2));
        $this->assertSame([''], DisplayWidth::wrap('', width: 5));
        $this->assertSame(['a', 'b'], DisplayWidth::wrap('a b', width: 0));
        $this->assertSame(['x', '日'], DisplayWidth::wrap('x 日', width: 1));
    }
}
