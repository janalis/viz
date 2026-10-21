<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Preprocessor;

use Janalis\Viz\PlantUML\Preprocessor\JsonError;
use Janalis\Viz\PlantUML\Preprocessor\JsonReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonReaderTest extends TestCase
{
    public function testJsonIsReadAndWrittenAsMinimalJsonDoes(): void
    {
        $json = JsonReader::parse("{\"a\": [1.5e+2, 0, 12.34e10, -3E-1, true, false, null], // a comment\n \"b\": {}, \"c\": \"\\\"\\\\\\/\\b\\f\\n\\r\\t\\u00e9\\u2028\", \"a\": 2}");

        $this->assertSame('{"a":[1.5e+2,0,12.34e10,-3E-1,true,false,null],"b":{},"c":"\"\\\\/\u0008\u000c\n\r\té\u2028","a":2}', $json->write());
        $this->assertSame('2', $json->get('a')?->write(), 'the last member of a name is the one read');
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function errors(): iterable
    {
        yield 'something after' => ['1 2', 'Unexpected character', 2];
        yield 'an array unclosed' => ['[1 2]', "Expected ',' or ']'", 3];
        yield 'an object unclosed' => ['{"a": 1 "b"', "Expected ',' or '}'", 8];
        yield 'a name unquoted' => ['{a: 1}', 'Expected name', 1];
        yield 'no colon' => ['{"a" 1}', "Expected ':'", 5];
        yield 'one slash' => ['{/"a": 1}', 'Expected Error in comment', 2];
        yield 'a comment to the end' => ['{"a": 1 // nothing after', 'Unexpected end of input', 24];
        yield 'a control character' => ["\"a\tb\"", 'Expected valid string character', 2];
        yield 'an unknown escape' => ['"\\x"', 'Expected valid escape sequence', 3];
        yield 'a short unicode escape' => ['"\\u12"', 'Expected hexadecimal digit', 5];
        yield 'a string unclosed' => ['"abc', 'Unexpected end of input', 4];
        yield 'a minus alone' => ['-', 'Expected digit', 1];
        yield 'a point alone' => ['1.', 'Expected digit', 2];
        yield 'a literal misspelt' => ['nul', "Expected 'l'", 3];
        yield 'nothing' => ['', 'Expected value', 0];
    }

    #[DataProvider('errors')]
    public function testWhereTheTextStopsBeingJsonIsTold(string $text, string $message, int $offset): void
    {
        try {
            JsonReader::parse($text);
            $this->fail('Expected an error');
        } catch (JsonError $error) {
            $this->assertSame([$message, $offset], [$error->getMessage(), $error->offset]);
        }
    }
}
