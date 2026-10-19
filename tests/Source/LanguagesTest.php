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
            $this->assertContains(Family::Graph, $languages->reader($format)->families(), $format->label());
        }
        foreach (SourceFormat::cases() as $format) {
            $this->assertContains(Family::Graph, $languages->writer($format)->families(), $format->label());
        }
        $this->assertSame([Family::Graph], $languages->writer(SourceFormat::Dot)->families(), 'DOT has only the graph');
    }

    public function testPlantUmlIsReadForTheFamiliesItDrawsNatively(): void
    {
        $this->assertSame(
            [Family::Sequence, Family::Classes, Family::State, Family::UseCase, Family::Graph],
            new Languages()
                ->reader(SourceFormat::PlantUml)
                ->families(),
        );
    }

    public function testEachLanguageIsNamedAsItsDocumentationWritesIt(): void
    {
        $this->assertSame(['Mermaid', 'DOT', 'PlantUML'], \array_map(static fn (SourceFormat $format): string => $format->label(), SourceFormat::cases()));
    }
}
