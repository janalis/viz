<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\RenderFailure;

/** Graphviz's `dot` command, for what only it knows: its own layout of a graph, and its JSON reading of the language. */
final readonly class DotTool
{
    public function __construct(
        private Tool $tool = new Tool('dot'),
        private Cli $cli = new Cli(),
    ) {}

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
        return $this->tool->path() ?? throw new RenderFailure(new DotCommand()->missing());
    }
}
