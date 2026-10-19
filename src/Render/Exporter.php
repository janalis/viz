<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Writes a diagram to a file only once it has rendered in full: a failure leaves an earlier export intact. */
final readonly class Exporter
{
    public function __construct(
        private Renderer $renderer,
        private Filesystem $files = new Filesystem(),
    ) {}

    /**
     * @param ?Format $format null takes it from the path's extension
     * @throws RenderFailure when the render fails, the format is unknown, or the file cannot be written
     */
    public function export(string $source, string $path, ?Format $format = null, RenderOptions $options = new RenderOptions()): Image
    {
        $format ??= Format::forPath($path) ?? throw new RenderFailure(\sprintf('Cannot tell an image format from "%s"', $path));
        $image = $this->renderer->render($source, $format, $options);
        try {
            $this->files->dumpFile($path, $image->bytes);
        } catch (IOExceptionInterface $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }

        return $image;
    }
}
