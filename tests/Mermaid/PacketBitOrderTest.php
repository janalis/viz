<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\PacketLayout;
use Janalis\Viz\Mermaid\PacketParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PacketBitOrderTest extends TestCase
{
    public function testRowsAndNumberEndpointsMirrorWithoutChangingFieldIdentity(): void
    {
        $parser = new PacketParser();
        foreach (new MermaidLexer("packet\n0: \"Flag\"\n+7: \"Version\"\n+32: \"Payload\"\n+1: \"Last\"", chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $source = $parser->finish();
        $ascending = new PacketLayout()->layout($source);
        \iterator_to_array($ascending);
        $descending = new PacketLayout(new MermaidConfiguration(packetBitOrder: 'descending'))->layout($source);
        \iterator_to_array($descending);
        $before = $ascending->getReturn();
        $after = $descending->getReturn();
        $this->assertSame($before->elements, $after->elements);
        $this->assertSame($before->legend, $after->legend);
        $this->assertSame($before->graph->width, $after->graph->width);
        foreach ($after->graph->nodes as $node) {
            if (!\array_key_exists($node->id, $after->elements)) {
                continue;
            }
            $old = $before->graph->nodes[$node->id];
            $this->assertSame(140.0 - (4.0 === $node->w ? 0.0 : 0.625), $node->x + $old->x);
            $this->assertSame([$old->name, $old->label, $old->drawLabel, $old->w, $old->h], [$node->name, $node->label, $node->drawLabel, $node->w, $node->h]);
            $this->assertSame($before->origin($old->id), $after->origin($node->id));
        }
        $numbers = [];
        foreach ($after->graph->nodes as $node) {
            if (!\str_starts_with($node->name, 'bit-')) {
                continue;
            }
            $numbers[$node->label] = $node->x;
        }
        $this->assertSame([31, 8, 7, 1, 0, 40, 39, 32], \array_keys($numbers));
        $this->assertGreaterThan($numbers[31], $numbers[8]);
        $this->assertGreaterThan($numbers[7], $numbers[1]);
        $this->assertGreaterThan($numbers[1], $numbers[0]);
        $this->assertGreaterThan($numbers[39], $numbers[32]);
        $this->assertSame(100.0, $numbers[40]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'boolean' => [false];
        yield 'number' => [1];
        yield 'unknown' => ['reverse'];
        yield 'case' => ['Descending'];
    }

    #[DataProvider('invalid')]
    public function testInvalidOrderIsRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet bitOrder must be ascending or descending');
        MermaidConfiguration::parse((object) ['packet' => (object) ['bitOrder' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testOrderCannotApplyToOtherFamilies(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetBitOrder: 'ascending')->validateFamily('other');
    }

    public function testFrontMatterDirectivesAndHiddenNumbersUseTheSameOrientation(): void
    {
        $prefix = "---\nconfig:\n  packet:\n    bitOrder: descending\n---\n";
        $body = "packet\n0-7: \"Low\"\n+24: \"High\"";
        $svg = NativeFixtureExport::render($prefix.$body, Format::Svg);
        $this->assertStringContainsString('x="117.6875" y=', $svg);
        $this->assertStringContainsString('>Low</text>', $svg);
        $reset = NativeFixtureExport::render($prefix."%%{init: {\"packet\": {\"bitOrder\": \"ascending\"}}}%%\n".$body, Format::Svg);
        $this->assertStringContainsString('x="21.6875" y=', $reset);
        $hidden = $prefix."%%{initialize: {\"packet\": {\"showBits\": false}}}%%\n".$body;
        $noTicks = NativeFixtureExport::render($hidden, Format::Svg);
        $this->assertStringContainsString('x="117.6875" y=', $noTicks);
        $this->assertStringNotContainsString('>0</text>', $noTicks);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach (['ascending', 'descending'] as $order) {
            $this->assertSame($order, MermaidConfiguration::parse((object) ['packet' => (object) ['bitOrder' => $order]], $statement)->packetBitOrder);
        }
        $configuration = new MermaidConfiguration(packetBitOrder: 'descending');
        $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
        $this->assertSame('descending', new MermaidConfiguration()->overlay($configuration)->packetBitOrder);
        $this->assertSame('ascending', $configuration->overlay(new MermaidConfiguration(packetBitOrder: 'ascending'))->packetBitOrder);
    }
}
