<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Process\Cli;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;

/**
 * PlantUML through its command: a launcher, or a `.jar` run by java. It is a JVM,
 * so a render costs about a second of startup before it draws anything.
 *
 * PlantUML shells out to Graphviz itself for several diagram types (class,
 * activity, state), and its PDF needs the Batik and FOP jars beside it; either
 * missing is reported on stderr, which the failure carries.
 */
final readonly class PlantUmlRenderer implements Renderer
{
    public function __construct(
        private Tool $tool = new Tool('plantuml'),
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
        $path = $this->tool->path() ?? throw new RenderFailure('plantuml not found: install it, or configure the path to plantuml or its .jar');
        $argv = PlantUmlCommand::render($path, $format, $options->dpi ?? PlantUmlCommand::BASE_DPI);

        return new Image($this->cli->run($argv, $source, $options->timeout), $format);
    }
}
