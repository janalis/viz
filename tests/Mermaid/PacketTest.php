<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\PacketDiagram;
use Janalis\Viz\Mermaid\PacketLayout;
use Janalis\Viz\Mermaid\PacketParser;
use Janalis\Viz\Text\DisplayWidth;

final class PacketTest extends TestCase
{
    private static function parse(string $source): PacketDiagram
    {
        $parser = new PacketParser();
        foreach (new MermaidLexer($source, chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new PacketLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testQuotedLabelsMetadataAndSourceLocations(): void
    {
        $source = "%% intro\n PACKET-BETA\n title A  packet; [literal %% comment\n accTitle: Accessible\n accDescr {\n A description\n}\n  0-15: \"Source ; %% #amp; \\\"quoted\\\"\"\n +16: 'Destination \\'quoted\\''\n 32: \"漢\n字\"";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('A packet; [literal', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame('A description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['Source ; %% & "quoted"', "Destination 'quoted'", "漢\n字"], \array_column($diagram->fields, 'label'));
        $this->assertSame([0, 16, 32], \array_column($diagram->fields, 'start'));
        $this->assertSame([15, 31, 32], \array_column($diagram->fields, 'end'));
        $this->assertSame([7, 8, 9], \array_column($diagram->fields, 'origin'));
        $tokens = \iterator_to_array(new MermaidLexer($source, chart: true)->statements());
        $field = \array_find($tokens, static fn ($statement): bool => \str_starts_with($statement->text, '0-15'));
        $this->assertSame(7, $field->line);
        $this->assertSame(2, $field->column);
        $this->assertSame(\strpos($source, needle: '0-15'), $field->offset);
        $this->assertSame('', self::parse("packet\ntitle\n0: \"\"")->metadata['title']['label']);
        $this->assertSame('Single line', self::parse("packet\naccDescr: Single line")->metadata['accdescr']['label']);
    }

    public function testFieldsSplitAcrossRowsWithoutExtraRowsAtExactBoundaries(): void
    {
        $scene = self::scene("packet\n0-10: \"First\"\n11-90: \"Continued\"\n+5: \"Tail\"");
        $nodes = \array_values($scene->selectionGraph->nodes);
        $this->assertSame(['packet-0', 'packet-11', 'packet-32', 'packet-64', 'packet-91'], \array_column($nodes, 'name'));
        $this->assertSame([43.375, 83.375, 127.375, 107.375, 19.375], \array_column($nodes, 'w'));
        $this->assertSame($nodes[0]->y, $nodes[1]->y);
        $this->assertLessThan($nodes[2]->y, $nodes[1]->y);
        $this->assertLessThan($nodes[3]->y, $nodes[2]->y);
        $this->assertSame($nodes[3]->y, $nodes[4]->y);
        $this->assertSame([1, 2, 2, 2, 3], \array_values($scene->elements));
        $this->assertSame('64–90: Continued', $nodes[3]->label);
        $this->assertSame('Continued', $nodes[3]->drawLabel);
        $this->assertSame(2, $scene->origins[(int) ($nodes[2]->y / 2)]);
        $exact = self::scene("packet\n+64: \"Two rows\"");
        $this->assertCount(2, $exact->elements);
        $this->assertSame('packet-32', \array_last($exact->selectionGraph->nodes)->name);
        $this->assertCount(0, $exact->graph->edges);
    }

    public function testCrowdedBitLabelsUseSeparateRowsAndNeverOverlap(): void
    {
        $scene = self::scene("packet\n0-1023: \"Header\"\n1024: \"A\"\n1025: \"B\"\n1026-1027: \"C\"");
        $ticks = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'bit-') && (int) $node->label >= 1024));
        $this->assertCount(4, $ticks);
        $this->assertGreaterThanOrEqual(2, \count(\array_unique(\array_column($ticks, 'y'))));
        foreach ($ticks as $index => $tick) {
            foreach (\array_slice($ticks, $index + 1) as $other) {
                if ($tick->y !== $other->y) {
                    continue;
                }
                $this->assertGreaterThan((\strlen($tick->label) + \strlen($other->label)) / 2, \abs($tick->x - $other->x));
            }
        }
        $fields = \array_values(\array_filter($scene->selectionGraph->nodes, static fn (GraphNode $node): bool => \in_array($node->name, ['packet-1024', 'packet-1025', 'packet-1026'], strict: true)));
        $this->assertCount(1, \array_unique(\array_column($fields, 'y')), 'all fields in a word share one baseline');
        $this->assertGreaterThan(\max(\array_column($ticks, 'y')) + 2, $fields[0]->y - ($fields[0]->h / 2));
    }

    public function testWrappedNarrowAndContinuedLabelsStayBounded(): void
    {
        $source = "packet\ntitle ".\str_repeat('Caption ', times: 30)."\n+1: \"".\str_repeat('漢', times: 300)."\"\n+63: \"Repeat\"";
        $scene = self::scene($source);
        foreach ($scene->selectionGraph->nodes as $node) {
            $this->assertLessThanOrEqual(134.0, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
            $this->assertLessThan(260, \mb_strlen($node->label));
            foreach (\explode("\n", $node->drawLabel) as $line) {
                $this->assertLessThanOrEqual($node->w - 2, DisplayWidth::of($line));
            }
        }
        $this->assertStringEndsWith('…', \array_first($scene->selectionGraph->nodes)->drawLabel);
        $this->assertCount(3, $scene->elements);
        $this->assertCount(0, self::scene('packet')->elements);
        $this->assertGreaterThan(0, self::scene('packet')->graph->height);
        $this->assertSame(PacketDiagram::MAX_BITS - 1, self::parse("packet\n+131072: \"Maximum\"")->fields[0]['end']);
    }

    #[DataProvider('invalidSources')]
    public function testInvalidPacketSourcesAreLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected packet'];
        yield ['graph TD', 'Expected packet'];
        yield ["packet\n1: \"Gap\"", 'expected bit 0'];
        yield ["packet\n0: \"First\"\n0: \"Overlap\"", 'expected bit 1'];
        yield ["packet\n0-3: \"First\"\n5: \"Gap\"", 'expected bit 4'];
        yield ["packet\n2-1: \"Reverse\"", 'positive width'];
        yield ["packet\n+0: \"Zero\"", 'positive width'];
        yield ["packet\n-1: \"Negative\"", 'Expected packet bit'];
        yield ["packet\n0.5: \"Fraction\"", 'Expected packet bit'];
        yield ["packet\n00: \"Leading zero\"", 'Expected packet bit'];
        yield ["packet\n0: Unquoted", 'Expected packet bit'];
        yield ["packet\n0: \"Label\" suffix", 'Expected packet bit'];
        yield ["packet\n0: \"Open", 'Unclosed'];
        yield ["packet\naccDescr {Open", 'Unclosed'];
        yield ['packet; 0: "Semicolon"', 'Expected packet'];
        yield ["packet\n+9999999999999999999999999: \"Overflow\"", 'packet bit limit'];
        yield ["packet\n0-131072: \"Too wide\"", 'packet field or bit limit'];
        yield ["packet\n0: \"First\"\n+131072: \"Too wide\"", 'packet field or bit limit'];
        $fields = \implode("\n", \array_map(static fn (int $index): string => $index.': "Bit"', \range(0, end: 4096)));
        yield ["packet\n".$fields, 'packet field or bit limit'];
    }
}
