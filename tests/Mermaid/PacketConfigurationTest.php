<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\PacketLayout;
use Janalis\Viz\Mermaid\PacketParser;

final class PacketConfigurationTest extends TestCase
{
    public function testHiddenBitsCompactRowsWithoutChangingFieldsOrTheirSourceIdentity(): void
    {
        $parser = new PacketParser();
        foreach (new MermaidLexer("packet\ntitle Header\n0-39: \"Payload\"\n+8: \"Trailer\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $normal = new PacketLayout()->layout($diagram);
        \iterator_to_array($normal);
        $hidden = new PacketLayout(new MermaidConfiguration(packetShowBits: false))->layout($diagram);
        \iterator_to_array($hidden);
        $before = $normal->getReturn();
        $after = $hidden->getReturn();
        $this->assertLessThan($before->graph->height, $after->graph->height);
        $this->assertSame($before->graph->width, $after->graph->width);
        $this->assertCount(3, $after->elements);
        $this->assertCount(4, $after->graph->nodes);
        $original = \array_column($before->graph->nodes, null, 'name');
        foreach ($after->graph->nodes as $node) {
            $old = $original[$node->name];
            $this->assertSame([$old->name, $old->label, $old->drawLabel, $old->x, $old->w, $old->h], [$node->name, $node->label, $node->drawLabel, $node->x, $node->w, $node->h]);
            $this->assertSame($before->origin($old->id), $after->origin($node->id));
        }
        $this->assertSame($before->legend, $after->legend);
        $this->assertSame([2, 2, 3], \array_values($after->elements));
    }

    public function testAbsentOptionsPreserveExistingValuesAndDefaults(): void
    {
        $empty = new MermaidConfiguration();
        $off = new MermaidConfiguration(packetShowBits: false);
        $this->assertSame($off, $off->overlay($empty));
        $this->assertFalse($empty->overlay($off)->packetShowBits);
        $this->assertTrue($off->overlay(new MermaidConfiguration(packetShowBits: true))->packetShowBits);
        $this->assertFalse($off->overlay(new MermaidConfiguration(pieHighlight: 'x'))->packetShowBits);
        $this->assertNull($empty->packetShowBits);
    }

    /** @return iterable<string, array{\stdClass, string, string}> */
    public static function invalid(): iterable
    {
        yield 'string' => [(object) ['packet' => (object) ['showBits' => 'false']], 'packet', 'Packet showBits must be boolean'];
        yield 'number' => [(object) ['packet' => (object) ['showBits' => 0]], 'packet', 'Packet showBits must be boolean'];
        yield 'mapping' => [(object) ['packet' => false], 'packet', 'sections must be mappings'];
        yield 'wrong family' => [(object) ['packet' => (object) ['showBits' => false]], 'sequence', 'does not apply'];
    }

    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsRejected(\stdClass $object, string $family, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        MermaidConfiguration::parse($object, new MermaidStatement('', offset: 0, line: 0, column: 0))->validateFamily($family);
    }
}
