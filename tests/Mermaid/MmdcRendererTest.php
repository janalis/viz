<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidCommand;
use Janalis\Viz\Mermaid\MmdcRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MmdcRendererTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testTheInvocationScalesFromTheBrowsersResolution(): void
    {
        $this->assertSame(
            ['mmdc', '--quiet', '--input', '-', '--outputFormat', 'png', '--output', '/out/d.png', '--scale', '2'],
            MermaidCommand::render('mmdc', Format::Png, '/out/d.png', dpi: 192),
        );
        $this->assertSame('1', MermaidCommand::render('mmdc', Format::Svg, 'o')[9]);
        $this->assertSame('0.010416666666667', MermaidCommand::render('mmdc', Format::Svg, 'o', dpi: 0)[9]);
    }

    public function testTheImageIsReadFromThePrivateOutputThenRemoved(): void
    {
        // mmdc writes the file named after --output; the seventh argument here.
        $this->tool = new FakeTool('cat > "$7"');

        $image = new MmdcRenderer(new Tool('mmdc', $this->tool->path()))->render("flowchart LR\n a-->b", Format::Pdf, new RenderOptions(dpi: 288));

        $this->assertSame("flowchart LR\n a-->b", $image->bytes);
        $this->assertSame(Format::Pdf, $image->format);
        $argv = $this->tool->argv();
        $this->assertSame(['--quiet', '--input', '-', '--outputFormat', 'pdf', '--output'], \array_slice($argv, offset: 0, length: 6));
        $this->assertStringEndsWith('/diagram.pdf', $argv[6]);
        $this->assertSame(['--scale', '3'], \array_slice($argv, offset: 7));
        $this->assertDirectoryDoesNotExist(\dirname($argv[6]));
        $this->assertTrue(new MmdcRenderer()->supports(Format::Png));
    }

    public function testNoOutputIsAFailureAndTheDirectoryStillGoes(): void
    {
        $this->tool = new FakeTool('exit 0');

        try {
            new MmdcRenderer(new Tool('mmdc', $this->tool->path()))->render('x', Format::Svg);
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
            new MmdcRenderer(new Tool('mmdc', $this->tool->path()))->render('x', Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame('Parse error on line 2', $failure->getMessage());
        }
        $this->assertDirectoryDoesNotExist(\dirname($this->tool->argv()[6]));
    }

    public function testAMissingToolSaysHowToFixIt(): void
    {
        $this->expectExceptionObject(new RenderFailure('mmdc not found: install @mermaid-js/mermaid-cli, or configure the path to mmdc'));

        new MmdcRenderer(new Tool('viz-no-such-mmdc'))->render('x', Format::Svg);
    }

    public function testEachRenderGetsAFreshPrivateDirectory(): void
    {
        $this->tool = new FakeTool('perl -e \'printf "%o", (stat shift)[2] & 0777\' "$(dirname "$7")" > "$7"');
        $renderer = new MmdcRenderer(new Tool('mmdc', $this->tool->path()));

        $this->assertSame('700', $renderer->render('x', Format::Svg)->bytes, 'only the owner can read the diagram');
        $first = $this->tool->argv()[6];
        $renderer->render('x', Format::Svg);
        $second = $this->tool->argv()[6];

        $this->assertMatchesRegularExpression('#^'.\preg_quote(\sys_get_temp_dir(), delimiter: '#').'/viz-mermaid-[0-9a-f]{32}/diagram\.svg$#', $first);
        $this->assertNotSame($first, $second);
    }
}
