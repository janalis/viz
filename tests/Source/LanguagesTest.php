<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Source;

use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\GraphViz\DotWriter;
use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Model\Family;
use Janalis\Viz\PlantUML\PlantUmlWriter;
use Janalis\Viz\Source\Languages;
use Janalis\Viz\Source\SourceFormat;
use Janalis\Viz\Source\UnsupportedConversion;
use PHPUnit\Framework\TestCase;

final class LanguagesTest extends TestCase
{
    public function testEachLanguageHasItsOwnReaderAndWriter(): void
    {
        $languages = new Languages();

        $this->assertInstanceOf(MermaidReader::class, $languages->reader(SourceFormat::Mermaid));
        $this->assertInstanceOf(DotReader::class, $languages->reader(SourceFormat::Dot));
        $this->assertInstanceOf(MermaidWriter::class, $languages->writer(SourceFormat::Mermaid));
        $this->assertInstanceOf(DotWriter::class, $languages->writer(SourceFormat::Dot));
        $this->assertInstanceOf(PlantUmlWriter::class, $languages->writer(SourceFormat::PlantUml));
    }

    public function testEveryReaderAndWriterHandlesTheGraph(): void
    {
        $languages = new Languages();

        foreach ([SourceFormat::Mermaid, SourceFormat::Dot] as $format) {
            $this->assertSame([Family::Graph], $languages->reader($format)->families(), $format->label());
        }
        foreach (SourceFormat::cases() as $format) {
            $this->assertSame([Family::Graph], $languages->writer($format)->families(), $format->label());
        }
    }

    public function testALanguageWithoutAReaderIsRefused(): void
    {
        $this->expectExceptionObject(new UnsupportedConversion('PlantUML cannot be read yet: only written'));

        new Languages()->reader(SourceFormat::PlantUml);
    }

    public function testEachLanguageIsNamedAsItsDocumentationWritesIt(): void
    {
        $this->assertSame(['Mermaid', 'DOT', 'PlantUML'], \array_map(static fn (SourceFormat $format): string => $format->label(), SourceFormat::cases()));
    }
}
