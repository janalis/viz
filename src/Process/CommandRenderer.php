<?php

declare(strict_types=1);

namespace Janalis\Viz\Process;

use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A language drawn by its own command-line tool, the source on stdin. A tool that
 * writes only to files gets a private directory per render, removed whatever happens.
 */
final readonly class CommandRenderer implements Renderer
{
    public function __construct(
        private Command $command,
        private Tool $tool,
        private Cli $cli = new Cli(),
        private Filesystem $files = new Filesystem(),
    ) {}

    #[\Override]
    public function supports(Format $format): bool
    {
        return true;
    }

    #[\Override]
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        $tool = $this->tool->path() ?? throw new RenderFailure($this->command->missing());
        $dpi = $options->dpi ?? $this->command->baseDpi();
        if (!$this->command->writesFile()) {
            return new Image($this->cli->run($this->command->argv($tool, $format, $dpi, null), $source, $options->timeout), $format);
        }
        $directory = \sys_get_temp_dir().'/viz-'.$this->command->name().'-'.\bin2hex(\random_bytes(16));
        $this->files->mkdir($directory, 0o700);
        $output = $directory.'/diagram.'.$format->extension();
        try {
            $this->cli->run($this->command->argv($tool, $format, $dpi, $output), $source, $options->timeout);
            $bytes = \is_file($output) ? (string) \file_get_contents($output) : '';
        } finally {
            $this->files->remove($directory);
        }
        if ('' === $bytes) {
            throw new RenderFailure($this->command->name().' produced no '.\strtoupper($format->value));
        }

        return new Image($bytes, $format);
    }
}
