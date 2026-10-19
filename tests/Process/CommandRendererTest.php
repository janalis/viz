<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Process;

use Janalis\Viz\GraphViz\DotCommand;
use Janalis\Viz\Mermaid\MermaidCommand;
use Janalis\Viz\PlantUML\PlantUmlCommand;
use Janalis\Viz\Process\CommandRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class CommandRendererTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testDotDrawsTheSourceFromStdin(): void
    {
        $this->tool = new FakeTool('cat');

        $image = new CommandRenderer(new DotCommand(), new Tool('dot', $this->tool->path()))->render('digraph { a }', Format::Png, new RenderOptions(dpi: 96));

        $this->assertSame('digraph { a }', $image->bytes);
        $this->assertSame(Format::Png, $image->format);
        $this->assertSame(['-Tpng', '-Gdpi=96', '-Gpad=0'], $this->tool->argv());
        $this->assertTrue(new CommandRenderer(new DotCommand(), new Tool('dot'))->supports(Format::Pdf));
    }

    public function testTheDefaultResolutionIsTheToolsOwn(): void
    {
        $this->tool = new FakeTool('cat');

        new CommandRenderer(new DotCommand(), new Tool('dot', $this->tool->path()))->render('g', Format::Png);
        $this->assertSame(['-Tpng', '-Gdpi=72', '-Gpad=0'], $this->tool->argv());
        new CommandRenderer(new PlantUmlCommand(), new Tool('plantuml', $this->tool->path()))->render('x', Format::Png);
        $this->assertSame('-Sdpi=96', $this->tool->argv()[5]);
    }

    public function testPlantUmlDrawsTheSourceFromStdinAtTheAskedResolution(): void
    {
        $this->tool = new FakeTool('cat');

        $image = new CommandRenderer(new PlantUmlCommand(), new Tool('plantuml', $this->tool->path()))->render("@startuml\n@enduml", Format::Png, new RenderOptions(dpi: 192));

        $this->assertSame("@startuml\n@enduml", $image->bytes);
        $this->assertSame(['-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi=192'], $this->tool->argv());
    }

    public function testAMissingToolSaysHowToFixIt(): void
    {
        foreach ([
            [new DotCommand(), 'graphviz not found: install it, or configure the path to dot'],
            [new PlantUmlCommand(), 'plantuml not found: install it, or configure the path to plantuml or its .jar'],
            [new MermaidCommand(), 'mmdc not found: install @mermaid-js/mermaid-cli, or configure the path to mmdc'],
        ] as [$command, $message]) {
            try {
                new CommandRenderer($command, new Tool('viz-no-such-tool'))->render('x', Format::Svg);
                $this->fail('Expected a failure');
            } catch (RenderFailure $failure) {
                $this->assertSame($message, $failure->getMessage());
            }
        }
    }

    public function testMmdcScalesFromTheBrowsersResolution(): void
    {
        $command = new MermaidCommand();

        $this->assertSame(
            ['mmdc', '--quiet', '--input', '-', '--outputFormat', 'png', '--output', '/out/d.png', '--scale', '2'],
            MermaidCommand::render('mmdc', Format::Png, '/out/d.png', dpi: 192),
        );
        $this->assertSame('1', MermaidCommand::render('mmdc', Format::Svg, 'o')[9]);
        $this->assertSame('0.010416666666667', MermaidCommand::render('mmdc', Format::Svg, 'o', dpi: 0)[9]);
        $this->assertSame('', $command->argv('mmdc', Format::Svg, 96, null)[7]);
        $this->assertSame(['mmdc', 96, true], [$command->name(), $command->baseDpi(), $command->writesFile()]);
    }

    public function testTheImageIsReadFromThePrivateOutputThenRemoved(): void
    {
        // mmdc writes the file named after --output; the seventh argument here.
        $this->tool = new FakeTool('cat > "$7"');

        $image = self::mmdc($this->tool)->render("flowchart LR\n a-->b", Format::Pdf, new RenderOptions(dpi: 288));

        $this->assertSame("flowchart LR\n a-->b", $image->bytes);
        $this->assertSame(Format::Pdf, $image->format);
        $argv = $this->tool->argv();
        $this->assertSame(['--quiet', '--input', '-', '--outputFormat', 'pdf', '--output'], \array_slice($argv, offset: 0, length: 6));
        $this->assertStringEndsWith('/diagram.pdf', $argv[6]);
        $this->assertSame(['--scale', '3'], \array_slice($argv, offset: 7));
        $this->assertDirectoryDoesNotExist(\dirname($argv[6]));
    }

    public function testNoOutputIsAFailureAndTheDirectoryStillGoes(): void
    {
        $this->tool = new FakeTool('exit 0');

        try {
            self::mmdc($this->tool)->render('x', Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame('mmdc produced no SVG', $failure->getMessage());
        }
        $this->assertDirectoryDoesNotExist(\dirname($this->tool->argv()[6]));
    }

    public function testAFailingToolStillRemovesItsDirectory(): void
    {
        $this->tool = new FakeTool('echo "Parse error on line 2" >&2; exit 1');

        try {
            self::mmdc($this->tool)->render('x', Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame('Parse error on line 2', $failure->getMessage());
        }
        $this->assertDirectoryDoesNotExist(\dirname($this->tool->argv()[6]));
    }

    public function testEachRenderGetsAFreshPrivateDirectory(): void
    {
        $this->tool = new FakeTool('perl -e \'printf "%o", (stat shift)[2] & 0777\' "$(dirname "$7")" > "$7"');
        $renderer = self::mmdc($this->tool);

        $this->assertSame('700', $renderer->render('x', Format::Svg)->bytes, 'only the owner can read the diagram');
        $first = $this->tool->argv()[6];
        $renderer->render('x', Format::Svg);
        $second = $this->tool->argv()[6];

        $this->assertMatchesRegularExpression('#^'.\preg_quote(\sys_get_temp_dir(), delimiter: '#').'/viz-mmdc-[0-9a-f]{32}/diagram\.svg$#', $first);
        $this->assertNotSame($first, $second);
    }

    private static function mmdc(FakeTool $tool): CommandRenderer
    {
        return new CommandRenderer(new MermaidCommand(), new Tool('mmdc', $tool->path()));
    }
}
