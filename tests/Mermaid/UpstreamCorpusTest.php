<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every pinned upstream end-to-end diagram, verbatim, renders natively: the picture exports,
 * configuration and all. Family tests check what they show; drawing terminal rows is Porta's,
 * which checks a diagram of each family.
 */
final class UpstreamCorpusTest extends TestCase
{
    private const string ROOT = __DIR__.'/../fixtures/mermaid/upstream/';

    /** @return array{commit: string, files: array<string, string>} */
    private static function provenance(): array
    {
        /** @var array{commit: string, files: array<string, string>} */
        return \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/upstream-provenance.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheCorpusIsThePinnedOneInFull(): void
    {
        $this->assertSame('21f72f07ea22c0af48a3149c550654e80d8e40cb', self::provenance()['commit']);
        $files = \iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS)));
        $this->assertCount(\count(self::provenance()['files']), $files, 'no file is added or missing');
    }

    /** @return iterable<string, array{string, string}> */
    public static function diagrams(): iterable
    {
        foreach (self::provenance()['files'] as $path => $hash) {
            yield $path => [$path, $hash];
        }
    }

    /**
     * Every pinned diagram, verbatim, exports natively. Moved here from Porta,
     * which ran the whole corpus through this library on every check of its
     * own and keeps a sample: this library is what the corpus tests.
     */
    #[DataProvider('diagrams')]
    public function testEachDiagramRendersNatively(string $path, string $hash): void
    {
        $source = (string) \file_get_contents(self::ROOT.$path);
        $this->assertSame($hash, \hash('sha256', $source), 'the file is the pinned one');
        $this->assertStringStartsWith('<svg', NativeFixtureExport::render($source, Format::Svg));
    }
}
