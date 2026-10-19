<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;

/** Graphviz through its `dot` command. A dot built without libgd has no PNG, and says so on stderr. */
final readonly class DotRenderer implements Renderer
{
    public function __construct(
        private Tool $tool = new Tool('dot'),
        private Cli $cli = new Cli(),
    ) {}

    #[\Override]
    public function supports(Format $format): bool
    {
        return true;
    }

    #[\Override]
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        $argv = DotCommand::render($this->path(), $format, $options->dpi ?? DotCommand::BASE_DPI);

        return new Image($this->cli->run($argv, $source, $options->timeout), $format);
    }

    /**
     * The graph as dot lays it out, in points with the origin top left.
     *
     * @throws RenderFailure when dot is missing, fails, or answers something unreadable
     */
    public function layout(string $source, float $timeout = 60.0): GraphLayout
    {
        $json = $this->json($source, $timeout);
        try {
            return DotJsonParser::parse($json);
        } catch (GraphParseException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * dot's own `-Tjson0` answer: the layout plus every graph attribute as written.
     *
     * @throws RenderFailure when dot is missing or fails
     */
    public function json(string $source, float $timeout = 60.0): string
    {
        return $this->cli->run(DotCommand::layout($this->path()), $source, $timeout);
    }

    private function path(): string
    {
        return $this->tool->path() ?? throw new RenderFailure('graphviz not found: install it, or configure the path to dot');
    }
}
