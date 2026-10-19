<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Source;

use Janalis\Viz\Source\SourceFormat;
use PHPUnit\Framework\TestCase;

final class SourceFormatTest extends TestCase
{
    public function testAPathNamesItsLanguageByExtension(): void
    {
        $this->assertSame(SourceFormat::Mermaid, SourceFormat::forPath('docs/flow.mmd'));
        $this->assertSame(SourceFormat::Mermaid, SourceFormat::forPath('flow.MERMAID'));
        $this->assertSame(SourceFormat::Dot, SourceFormat::forPath('graph.gv'));
        $this->assertSame(SourceFormat::PlantUml, SourceFormat::forPath('seq.wsd'));
        $this->assertNull(SourceFormat::forPath('README.md'));
        $this->assertSame(['dot', 'gv'], SourceFormat::Dot->extensions());
        $this->assertSame(['puml', 'plantuml', 'pu', 'iuml', 'wsd'], SourceFormat::PlantUml->extensions());
    }

    public function testAFenceNamesItsLanguage(): void
    {
        $this->assertSame(SourceFormat::Mermaid, SourceFormat::forFence(' Mermaid '));
        $this->assertSame(SourceFormat::Dot, SourceFormat::forFence('graphviz'));
        $this->assertSame(SourceFormat::PlantUml, SourceFormat::forFence('puml'));
        $this->assertNull(SourceFormat::forFence('php'));
        $this->assertSame(['mermaid'], SourceFormat::Mermaid->fences());
        $this->assertSame(['dot', 'graphviz'], SourceFormat::Dot->fences());
        $this->assertSame(['plantuml', 'puml'], SourceFormat::PlantUml->fences());
    }
}
