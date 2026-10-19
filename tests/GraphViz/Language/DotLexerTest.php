<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Language;

use Janalis\Viz\GraphViz\Language\DotLexer;
use Janalis\Viz\GraphViz\Language\DotSyntaxError;
use Janalis\Viz\GraphViz\Language\DotToken;
use Janalis\Viz\GraphViz\Language\DotTokenKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DotLexerTest extends TestCase
{
    public function testEveryKindOfTokenIsRead(): void
    {
        $tokens = self::tokens("STRICT DiGraph g_1 {\n  a -> b -- c [x=1, y=-2.5; z=.5] : ; = \"q\\\"uote\" <a<b>c> é }");

        $this->assertSame(
            [
                ['keyword', 'strict'],
                ['keyword', 'digraph'],
                ['id',      'g_1'],
                ['{',       '{'],
                ['id',      'a'],
                ['edgeop',  '->'],
                ['id',      'b'],
                ['edgeop',  '--'],
                ['id',      'c'],
                ['[',       '['],
                ['id',      'x'],
                ['=',       '='],
                ['id',      '1'],
                [',',       ','],
                ['id',      'y'],
                ['=',       '='],
                ['id',      '-2.5'],
                [';',       ';'],
                ['id',      'z'],
                ['=',       '='],
                ['id',      '.5'],
                [']',       ']'],
                [':',       ':'],
                [';',       ';'],
                ['=',       '='],
                ['id',      'q"uote'],
                ['html',    'a<b>c'],
                ['id',      'é'],
                ['}',       '}'],
                ['end',     ''],
            ],
            \array_map(static fn (DotToken $read): array => [$read->kind->value, $read->text], $tokens),
        );
        $this->assertSame([1, 2], [$tokens[4]->line, $tokens[4]->column]);
        $this->assertTrue($tokens[25]->quoted);
        $this->assertFalse($tokens[2]->quoted);
    }

    public function testCommentsPreprocessorLinesAndJoinedStringsAreSkipped(): void
    {
        $tokens = self::tokens("# line\n  # indented\n// comment\na /* block\n */ \"x\" +\n \"y\" + \"z\" \"after\\\nwrap\\\r\nped\"");

        $this->assertSame(['a', 'xyz', 'afterwrapped', ''], \array_map(static fn (DotToken $read): string => $read->text, \array_slice($tokens, offset: 0, length: 4)));
    }

    public function testATrailingCommentEndsTheSource(): void
    {
        $this->assertSame(DotTokenKind::End, self::tokens('a // done')[1]->kind);
    }

    #[DataProvider('errors')]
    public function testMalformedSourceIsLocated(string $source, string $message): void
    {
        $this->expectExceptionObject(new DotSyntaxError($message, 0, 0, 0));
        $this->expectExceptionMessage($message);

        self::tokens($source);
    }

    /** @return iterable<string, array{string, string}> */
    public static function errors(): iterable
    {
        yield 'comment' => ["a\n /* open", 'Line 2, column 2: Unclosed comment'];
        yield 'string' => ['"open', 'Line 1, column 1: Unclosed quoted string'];
        yield 'html' => ['<a<b>', 'Line 1, column 1: Unclosed HTML string'];
        yield 'plus' => ['"a" + b', 'Line 1, column 7: Expected a quoted string after +'];
        yield 'numeral' => ['1abc', 'Line 1, column 1: A numeral cannot run into an identifier'];
        yield 'stray' => ['a ! b', 'Line 1, column 3: Unexpected character !'];
    }

    /** @return list<DotToken> */
    private static function tokens(string $source): array
    {
        return \iterator_to_array(new DotLexer($source)->tokens(), preserve_keys: false);
    }
}
