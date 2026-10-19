<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidKinds;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Source\UnsupportedConversion;
use PHPUnit\Framework\TestCase;

final class MermaidKindsTest extends TestCase
{
    public function testTheFamilyIsReadPastFrontMatterAndDirectives(): void
    {
        $this->assertSame('state', MermaidKinds::detect("---\ntitle: T\n---\n%%{init: {}}%%\nstateDiagram-v2\n  a --> b"));
        $this->assertSame('sequence', MermaidKinds::detect("sequenceDiagram\n  A->>B: hi"));
        $this->assertSame('flowchart', MermaidKinds::detect("graph TD\n  a --> b"));
        $this->assertSame('eventmodeling', MermaidKinds::header("eventmodeling\n"));
    }

    public function testAWriterRefusesAFamilyItHasNoFormFor(): void
    {
        $diagram = new readonly class implements Diagram {
            #[\Override]
            public function family(): Family
            {
                return Family::Sequence;
            }

            #[\Override]
            public function graph(): ?Graph
            {
                return null;
            }
        };

        $this->expectExceptionObject(new UnsupportedConversion('A sequence diagram has no Mermaid writer'));

        new MermaidWriter()->write($diagram);
    }
}
