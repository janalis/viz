<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Text;

use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Text\Font;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class FontTest extends TestCase
{
    private ?string $directory = null;

    #[After]
    protected function removeArchive(): void
    {
        if (null !== $this->directory) {
            new Filesystem()->remove($this->directory);
            $this->directory = null;
        }
    }

    /** A tar archive holding the bundled font, read through the `phar://` stream as a packaged build would. */
    private function archive(): string
    {
        $files = new Filesystem();
        $this->directory = $files->tempnam(\sys_get_temp_dir(), prefix: 'viz-font-test-');
        $files->remove($this->directory);
        $files->mkdir($this->directory);
        $archive = new \PharData($this->directory.'/font.tar');
        $archive->addFile(Font::FILE, 'font.ttf');

        return 'phar://'.$this->directory.'/font.tar/font.ttf';
    }

    public function testMissingPackagedFontIsReportedBeforeCallingFreeType(): void
    {
        $font = new Font('phar:///nonexistent/font.tar/font.ttf');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot read');
        $font->path();
    }

    public function testPharFontIsMaterializedAndRemovedWithoutTouchingItsSource(): void
    {
        $source = $this->archive();
        $font = new Font($source);
        $path = $font->path();
        $this->assertStringStartsNotWith('phar://', $path);
        $this->assertFileExists($path);
        $this->assertSame($path, $font->path(), 'one lease reuses one extraction');
        $this->assertFileEquals(Font::FILE, $path);
        $this->assertSame(0o600, \fileperms($path) & 0o777);
        $font->close();
        $this->assertFileDoesNotExist($path);
        $font->close();
        $this->assertFileExists($source);
        $again = $font->path();
        $this->assertFileExists($again, 'a closed lease extracts afresh');
        $font->close();
        $this->assertFileDoesNotExist($again);
        $local = new Font();
        $this->assertSame(Font::FILE, $local->path());
        $local->close();
        $this->assertFileExists(Font::FILE, 'closing a local font never removes the bundled file');

        $work = new MermaidPng(font: $source)->render(new MermaidDrawing(20, 10, [new MermaidMark('text', [[10.0, 5.0]], 'Packaged')]));
        \iterator_to_array($work);
        $this->assertStringStartsWith("\x89PNG", $work->getReturn());
    }

    public function testExtractionErrorsAreReportedAsRenderFailures(): void
    {
        $files = new class extends Filesystem {
            #[\Override]
            public function tempnam(string $dir, string $prefix, string $suffix = ''): string
            {
                throw new IOException('disk full');
            }
        };
        $font = new Font($this->archive(), $files);
        try {
            $font->path();
            $this->fail('A failed extraction was accepted');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Cannot prepare the bundled Mermaid font: disk full', $error->getMessage());
            $this->assertInstanceOf(IOException::class, $error->getPrevious());
        }
    }
}
