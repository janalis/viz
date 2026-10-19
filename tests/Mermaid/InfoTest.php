<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\InfoLexer;
use Janalis\Viz\Mermaid\InfoParser;
use Janalis\Viz\Mermaid\MermaidGraph;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InfoTest extends TestCase
{
    private static function parse(string $source): MermaidGraph
    {
        $parser = new InfoParser();
        foreach (new InfoLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntaxHasAnExactSourceLocation(string $source, string $message, int $line, int $column): void
    {
        try {
            self::parse($source);
            $this->fail('Invalid info syntax accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame($line, $error->sourceLine);
            $this->assertSame($column, $error->column);
        }
    }

    /** @return iterable<array{string, string, int, int}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected info', 0, 0];
        yield ['showInfo', 'Expected info', 0, 0];
        yield ['info unsupported', 'Invalid info declaration', 0, 5];
        yield ['Info', 'Invalid info declaration', 0, 0];
        yield ['information', 'Invalid info declaration', 0, 0];
        yield ['info showInfo showInfo', 'must occur once', 0, 14];
        yield ["info\ntitle Header\nshowInfo", 'must occur once', 2, 0];
        yield ["info\ninfo", 'Expected info title', 1, 0];
        yield ["info\naccDescr {\nunclosed", 'Invalid info declaration', 1, 0];
        yield ["info\naccDescr { closed } garbage", 'Invalid info declaration', 1, 0];
        yield ['info;', 'Invalid info declaration', 0, 4];
    }

    public function testLexerRetainsByteOffsetAndUnicodeColumns(): void
    {
        $source = "%% first\n  info showInfo\naccTitle: 日本語";
        $statements = \iterator_to_array(new InfoLexer($source)->statements());
        $this->assertSame(11, $statements[0]->offset);
        $this->assertSame(2, $statements[0]->column);
        $this->assertSame(7, $statements[1]->column);
        $this->assertSame('accTitle: 日本語', $statements[2]->text);
    }

    public function testDirectParserRejectsUnknownMetadata(): void
    {
        $parser = new InfoParser();
        $parser->accept(new MermaidStatement('info', offset: 0, line: 0, column: 0));
        $this->expectExceptionMessageIsOrContains('Expected info title or accessibility metadata');
        $parser->accept(new MermaidStatement('unknown', offset: 5, line: 1, column: 0));
    }

    public function testMetadataHasTheSharedStatementLimit(): void
    {
        $this->expectExceptionMessageIsOrContains('Statement exceeds the size limit');
        self::parse("info\ntitle ".\str_repeat('x', times: 65_536));
    }

    public function testLongMetadataWithSinglePercentSignsDoesNotExhaustTheLexer(): void
    {
        $metadata = \str_repeat('x%', times: 30_000);
        $graph = self::parse("info\ntitle ".$metadata."\naccTitle: ".$metadata."\naccDescr: ".$metadata."\naccDescr { ".$metadata.' }');
        $this->assertCount(1, $graph->nodes);
        $this->assertSame(InfoParser::LABEL, $graph->nodes['info']['label']);
    }

    public function testNativeExportsIdentifyTheTargetWithoutClaimingARuntimeVersion(): void
    {
        $svg = NativeFixtureExport::render('info showInfo', Format::Svg);
        $this->assertStringContainsString('Janalis Viz native Mermaid', $svg);
        $this->assertStringContainsString('Compatibility target: v12.1.0', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
        $png = NativeFixtureExport::render('info', Format::Png);
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertInstanceOf(\GdImage::class, \imagecreatefromstring($png));
    }
}
