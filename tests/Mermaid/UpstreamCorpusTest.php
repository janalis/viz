<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\TestCase;

/**
 * Every pinned upstream end-to-end diagram, verbatim, renders natively: the picture exports
 * and the terminal rows draw, configuration and all. Family tests check what they show.
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
}
