<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceMenus;
use Janalis\Viz\Mermaid\SequenceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SequenceMenusTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testMenusPreserveLinksPropertiesReferencesAndOrigins(): void
    {
        $diagram = self::parse(<<<'MERMAID'
            sequenceDiagram
            link A: Wiki @ https://example.test/wiki#35;chapter#59;part=1#37;#37;literal
            links A: {"Wiki":"https://example.test/replaced?x=1&amp;y=2", "#quot;Quoted#quot;":"https://example.test/#35;anchor", "0":"zero"}
            properties A: {"count":2,"fraction":1.0,"active":true,"empty":null,"nested":{"items":[1,"é"]},"description":"note<br>line; # literal %%"}
            details A: external-details
            participant A as Alice
            participant B
            A->>B: hello
            properties A: {"count":3}
            MERMAID);
        $this->assertSame(['A', 'B'], \array_keys($diagram->participants));
        $this->assertSame('Alice', $diagram->participants['A']['label']);
        $this->assertCount(1, $diagram->events);
        $this->assertCount(10, $diagram->menus['A']);
        $this->assertSame('https://example.test/replaced?x=1&y=2', $diagram->menus['A']['links:Wiki']['value']);
        $this->assertSame(2, $diagram->menus['A']['links:Wiki']['origin']);
        $this->assertSame('https://example.test/#anchor', $diagram->menus['A']['links:"Quoted"']['value']);
        $this->assertSame('zero', $diagram->menus['A']['links:0']['value']);
        $this->assertSame(['kind' => 'properties', 'label' => 'count', 'value' => '3', 'origin' => 8], $diagram->menus['A']['properties:count']);
        $this->assertSame('1.0', $diagram->menus['A']['properties:fraction']['value']);
        $this->assertSame('true', $diagram->menus['A']['properties:active']['value']);
        $this->assertSame('null', $diagram->menus['A']['properties:empty']['value']);
        $this->assertSame('{"items":[1,"é"]}', $diagram->menus['A']['properties:nested']['value']);
        $this->assertSame("note\nline; # literal %%", $diagram->menus['A']['properties:description']['value']);
        $this->assertSame('external-details', $diagram->menus['A']['details:reference']['value']);
        $single = self::parse("sequenceDiagram\nlink 0: Account #64; work @ https://example.test/#35;hash#59;literal#37;#37;name\n0->>B: hi");
        $this->assertSame('https://example.test/#hash;literal%%name', $single->menus[0]['links:Account @ work']['value']);
        $this->assertSame([], self::parse("sequenceDiagram\nlinks A: {}\nproperties A: {}")->menus);
    }

    public function testMenuBoundariesPreserveFollowingMessagesAndComments(): void
    {
        $source = "sequenceDiagram; link A: Docs @ https://example.test; A->>B: hello; links A: {\"More\":\"https://example.test/next\"}; B->>A: bye\nproperties A: {\"value\":5}; A->>B: again # trailing comment\ndetails A: reference; B->>A: end %% trailing comment";
        $diagram = self::parse($source);
        $this->assertSame(['hello', 'bye', 'again', 'end'], \array_column($diagram->events, 'label'));
        $this->assertSame('https://example.test', $diagram->menus['A']['links:Docs']['value']);
        $this->assertSame('https://example.test/next', $diagram->menus['A']['links:More']['value']);
        $this->assertSame('reference', $diagram->menus['A']['details:reference']['value']);
    }

    public function testLongUnicodeValuesWrapWithoutDroppingTheirContent(): void
    {
        $url = 'https://example.test/'.\str_repeat('路径', times: 200);
        $diagram = self::parse("sequenceDiagram\nlink A: Documentation @ ".$url);
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $panel = \array_last($scene->graph->nodes);
        $this->assertGreaterThan(6, $panel->h);
        $this->assertLessThanOrEqual(120, $panel->w);
        $this->assertLessThanOrEqual($scene->graph->width, $panel->x + ($panel->w / 2));
        $this->assertStringContainsString($url, \str_replace("\n", replace: '', subject: $panel->label));
        $this->assertSame(1, \array_last($scene->origins));
    }

    /** @return iterable<string, array{string,string}> */
    public static function invalid(): iterable
    {
        yield 'link separator' => ['link A: Wiki', 'label @ URL'];
        yield 'link label' => ['link A: @ https://example.test', 'label @ URL'];
        yield 'link URL' => ['link A: Wiki @', 'label @ URL'];
        yield 'details' => ['details A:', 'element reference'];
        yield 'JSON' => ['links A: {"bad":}', 'Invalid sequence menu JSON'];
        yield 'non-object' => ['links A: []', 'must be an object'];
        yield 'wrong URL' => ['links A: {"bad":123}', 'URLs must be strings'];
        yield 'invalid endpoint' => ['links A@{}: {}', 'Invalid sequence participant'];
        yield 'numeric overflow' => ['properties A: {"huge":1e400}', 'numeric value limit'];
        yield 'nested overflow' => ['properties A: {"huge":{"nested":1e400}}', 'numeric value limit'];
        yield 'group' => ['box Group; link A: Wiki @ https://example.test', 'only participant'];
    }

    #[DataProvider('invalid')]
    public function testMalformedMenusHaveLocatedErrors(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        try {
            self::parse("sequenceDiagram\n".$source);
        } catch (MermaidSyntaxError $error) {
            $this->assertStringStartsWith('Line 2,', $error->getMessage());
            throw $error;
        }
    }

    public function testMenuLimitCountsUniqueEntriesAcrossActorsAndAllowsReplacement(): void
    {
        $diagram = new SequenceDiagram();
        $menus = new SequenceMenus($diagram);
        $statement = new MermaidStatement('menu', offset: 0, line: 1, column: 0);
        for ($i = 0; $i < 4096; ++$i) {
            $menus->accept('link', (string) ($i % 2), 'entry'.$i.' @ value', $statement);
        }
        $menus->accept('link', '0', 'entry0 @ replaced', $statement);
        $this->assertSame('replaced', $diagram->menus[0]['links:entry0']['value']);
        $this->assertCount(2048, $diagram->menus[0]);
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('menu item limit');
        $menus->accept('details', '0', 'reference', $statement);
    }
}
