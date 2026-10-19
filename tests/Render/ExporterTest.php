<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Render;

use Janalis\Viz\Render\Exporter;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderOptions;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExporterTest extends TestCase
{
    private string $directory;

    #[Before]
    protected function createDirectory(): void
    {
        $this->directory = \sys_get_temp_dir().'/viz-export-'.\bin2hex(\random_bytes(8));
        \mkdir($this->directory);
    }

    #[After]
    protected function removeDirectory(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheFormatComesFromTheExtensionAndTheFileIsWritten(): void
    {
        $path = $this->directory.'/nested/diagram.pdf';

        $image = new Exporter(self::renderer())->export('graph', $path, options: new RenderOptions(dpi: 300));

        $this->assertSame(Format::Pdf, $image->format);
        $this->assertSame('graph as pdf at 300', \file_get_contents($path));
    }

    public function testAnExplicitFormatWinsOverTheExtension(): void
    {
        $path = $this->directory.'/diagram.out';

        new Exporter(self::renderer())->export('graph', $path, Format::Svg);

        $this->assertSame('graph as svg at ', \file_get_contents($path));
    }

    public function testAnUnknownExtensionIsRefusedBeforeRendering(): void
    {
        $this->expectExceptionObject(new RenderFailure('Cannot tell an image format from "diagram.gif"'));

        new Exporter(self::renderer(fails: true))->export('graph', 'diagram.gif');
    }

    public function testAFailedRenderLeavesTheEarlierExportIntact(): void
    {
        $path = $this->directory.'/diagram.svg';
        \file_put_contents($path, 'earlier');

        try {
            new Exporter(self::renderer(fails: true))->export('graph', $path);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame('broken', $failure->getMessage());
        }
        $this->assertSame('earlier', \file_get_contents($path));
    }

    public function testAnUnwritablePathIsAFailure(): void
    {
        $blocker = $this->directory.'/file';
        \file_put_contents($blocker, '');

        $this->expectException(RenderFailure::class);

        new Exporter(self::renderer())->export('graph', $blocker.'/diagram.svg');
    }

    // @mago-expect lint:no-boolean-flag-parameter -- A test double's one switch.
    private static function renderer(bool $fails = false): Renderer
    {
        return new readonly class($fails) implements Renderer {
            public function __construct(
                private bool $fails,
            ) {}

            #[\Override]
            public function supports(Format $format): bool
            {
                return true;
            }

            #[\Override]
            public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
            {
                return $this->fails ? throw new RenderFailure('broken') : new Image($source.' as '.$format->value.' at '.$options->dpi, $format);
            }
        };
    }
}
