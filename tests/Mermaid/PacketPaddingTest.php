<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\PacketLayout;
use Janalis\Viz\Mermaid\PacketParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PacketPaddingTest extends TestCase
{
    public function testRowGapsComposeWithSizingAndDescendingOrderWithoutChangingFields(): void
    {
        foreach ([0.0, 2.5, 10.0] as $padding) {
            $configuration = new MermaidConfiguration(packetShowBits: false, packetBitOrder: 'descending', packetBitsPerRow: 8, packetBitWidth: 48.0, packetRowHeight: 64.0, packetPaddingY: $padding);
            $parser = new PacketParser($configuration);
            foreach (new MermaidLexer("packet\n0-7: \"First\"\n+8: \"Second\"", chart: true)->statements() as $statement) {
                $parser->accept($statement);
            }
            $work = new PacketLayout($configuration)->layout($parser->finish());
            \iterator_to_array($work);
            $scene = $work->getReturn();
            [$first, $second] = $scene->graph->nodes;
            $gap = $padding * 0.8;
            $this->assertSame($gap, $second->y - ($second->h / 2) - ($first->y + ($first->h / 2)));
            $this->assertSame([47.375, 16.0, 29.6875], [$first->w, $first->h, $first->x]);
            $this->assertSame([$first->w, $first->h, $first->x], [$second->w, $second->h, $second->x]);
            $this->assertSame(34.0 + (2 * $gap), $scene->graph->height);
            $this->assertSame([1, 2], \array_values($scene->elements));
            $this->assertSame(1, $scene->origin($first->id));
            $this->assertSame(2, $scene->origin($second->id));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'negative' => [-0.5];
        yield 'large' => [1000.5];
        yield 'string' => ['5'];
        yield 'boolean' => [false];
        yield 'infinite' => [\INF];
        yield 'nan' => [\NAN];
    }

    #[DataProvider('invalid')]
    public function testInvalidPaddingIsRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet paddingY must be a finite number from 0 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['paddingY' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testZeroPaddingStillBelongsToThePacketFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetPaddingY: 0.0)->validateFamily('other');
    }
}
