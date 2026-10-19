<?php

declare(strict_types=1);

namespace Janalis\Viz\Text;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** FreeType needs a real file, even when the application and its font live inside a PHAR. */
final class Font
{
    public const string FILE = __DIR__.'/../../resources/fonts/IBMPlexMono-Regular.ttf';

    private ?string $temporary = null;

    public function __construct(
        private readonly string $source = self::FILE,
        private readonly Filesystem $files = new Filesystem(),
    ) {}

    public function path(): string
    {
        return \str_starts_with($this->source, 'phar://') ? $this->temporary ?? $this->extract() : $this->source;
    }

    public static function file(FontFace $face, string $source = self::FILE): string
    {
        return \str_replace('-Regular.ttf', '-'.$face->value.'.ttf', $source);
    }

    private function extract(): string
    {
        $bytes = \is_file($this->source) ? (string) \file_get_contents($this->source) : '';
        if ('' === $bytes) {
            throw new \InvalidArgumentException('Cannot read the bundled font');
        }
        try {
            $this->temporary = $this->files->tempnam(\sys_get_temp_dir(), prefix: 'viz-mermaid-font-');
            $this->files->dumpFile($this->temporary, $bytes);
        } catch (IOExceptionInterface $exception) {
            throw new \InvalidArgumentException('Cannot prepare the bundled font: '.$exception->getMessage(), previous: $exception);
        }

        return $this->temporary;
    }

    public function close(): void
    {
        if (null !== $this->temporary) {
            $this->files->remove($this->temporary);
            $this->temporary = null;
        }
    }
}
