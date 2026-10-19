<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Mermaid through mermaid-cli, which drives a headless browser: the reference
 * rendering, at the cost of Node and Chromium. It writes only to files, so each
 * render gets a private directory that is removed whatever happens.
 */
final readonly class MmdcRenderer implements Renderer
{
    public function __construct(
        private Tool $tool = new Tool('mmdc'),
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
        $tool = $this->tool->path() ?? throw new RenderFailure('mmdc not found: install @mermaid-js/mermaid-cli, or configure the path to mmdc');
        $directory = \sys_get_temp_dir().'/viz-mermaid-'.\bin2hex(\random_bytes(16));
        $this->files->mkdir($directory, 0o700);
        $output = $directory.'/diagram.'.$format->extension();
        try {
            $this->cli->run(MermaidCommand::render($tool, $format, $output, $options->dpi ?? MermaidCommand::BASE_DPI), $source, $options->timeout);
            $bytes = \is_file($output) ? (string) \file_get_contents($output) : '';
        } finally {
            $this->files->remove($directory);
        }
        if ('' === $bytes) {
            throw new RenderFailure('mmdc produced no '.\strtoupper($format->value));
        }

        return new Image($bytes, $format);
    }
}
