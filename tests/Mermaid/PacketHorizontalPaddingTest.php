<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;

final class PacketHorizontalPaddingTest extends TestCase
{
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
    public function testInvalidHorizontalPaddingIsRejected(mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Packet paddingX must be a finite number from 0 to 1000');
        MermaidConfiguration::parse((object) ['packet' => (object) ['paddingX' => $value]], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testZeroHorizontalPaddingCannotStyleAnotherFamily(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(packetPaddingX: 0.0)->validateFamily('other');
    }
}
