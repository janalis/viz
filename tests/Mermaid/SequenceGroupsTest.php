<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SequenceConfiguration;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceParser;

final class SequenceGroupsTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testConfigurationsAndAliasPrecedence(): void
    {
        $source = "sequenceDiagram\nparticipant A@{\n\"type\": \"boundary\",\n\"alias\": \"内部 as Name; #hash %%literal } \\\"quote\\\"\"\n}\nactor B@{\"type\":\"database\",\"alias\":\"Internal\"} as External\ncreate participant C@{\"alias\":\"New\",\"type\":\"queue\"}\nA->>C: hello\nC-->>B: reply";
        $diagram = self::parse($source);
        $this->assertSame('内部 as Name; #hash %%literal } "quote"', $diagram->participants['A']['label']);
        $this->assertSame('External', $diagram->participants['B']['label']);
        $this->assertSame('New', $diagram->participants['C']['label']);
        $this->assertSame(['A' => 'boundary', 'B' => 'database', 'C' => 'queue'], $diagram->participantTypes);
        $this->assertSame(1, $diagram->participants['A']['origin']);
        $this->assertSame(1, $diagram->lifetimes['C']['create']);
        $this->assertSame('B', $diagram->events[2]['to']);
        $this->assertSame([], self::parse('sequenceDiagram; participant A@{}')->participantTypes);
        $this->assertSame('Alias', self::parse('sequenceDiagram; create actor B@{"alias":"Alias"}; A->B: hi')->participants['B']['label']);
        $this->assertSame('Outer', self::parse('sequenceDiagram; create actor B@{"alias":"Inner"} as Outer; A->B: hi')->participants['B']['label']);
        foreach (['participant', 'actor', 'control', 'entity', 'collections'] as $type) {
            $this->assertSame($type, self::parse('sequenceDiagram; participant A@{"type":"'.$type.'"}')->participantTypes['A']);
        }
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertStringContainsString(' [boundary]', \implode("\n", \array_column($scene->legend, 'text')));
    }

    public function testEmptyConfiguredAliasUsesParticipantId(): void
    {
        $diagram = self::parse('sequenceDiagram; participant A@{"alias":""}; actor B@{"alias":"0"}; participant C@{"alias":""} as External; create actor D@{"alias":""}; A->>D: hello');
        $this->assertSame('A', $diagram->participants['A']['label']);
        $this->assertSame('0', $diagram->participants['B']['label']);
        $this->assertSame('External', $diagram->participants['C']['label']);
        $this->assertSame('D', $diagram->participants['D']['label']);
    }

    public function testMaximumActivationStackStaysInsideBox(): void
    {
        $diagram = self::parse('sequenceDiagram; box Team; participant A; end; '.\str_repeat('activate A; ', times: 128).'A->>A: active');
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        foreach ($scene->graph->nodes as $node) {
            if (!\str_starts_with($node->name, 'activation')) {
                continue;
            }
            $this->assertLessThan($scene->graph->clusters[0]->x1, $node->x + ($node->w / 2));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'nested' => ['box One; box Two; end', 'cannot nest'];
        yield 'message' => ['box One; A->B: invalid; end', 'only participant'];
        yield 'create' => ['box One; create actor A; end; B->A: hi', 'only participant'];
        yield 'control' => ['box One; loop hi; end; end', 'only participant'];
        yield 'unclosed' => ['box One; participant A', 'Unclosed sequence participant box'];
        yield 'duplicate group' => ['box One; participant A; end; box Two; participant A; end', 'another box'];
        yield 'truthy boolean field' => ['participant A@{"alias":true}', 'Unsupported sequence participant configuration field'];
        yield 'type value' => ['participant A@{"type":"unknown"}', 'Unsupported sequence participant type'];
        yield 'alias number' => ['participant A@{"alias":1}', 'Unsupported sequence participant configuration field'];
        yield 'message config' => ['A@{}->B: invalid', 'Invalid sequence participant'];
        yield 'note config' => ['Note over A@{}: invalid', 'Invalid sequence participant'];
        yield 'unclosed JSON' => ['participant A@{"alias":"unfinished}', 'Unclosed label'];
    }

    #[DataProvider('invalid')]
    public function testLocatedErrors(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse("sequenceDiagram\n".$source);
    }

    public function testObjectAndGroupSizeGuards(): void
    {
        try {
            SequenceConfiguration::parse('A@[]', new MermaidStatement('A@[]', offset: 0, line: 5, column: 0));
            $this->fail('Non-object accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('must be an object', $error->getMessage());
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('participant box limit');
        self::parse('sequenceDiagram; '.\str_repeat('box; end; ', times: 4097));
    }
}
