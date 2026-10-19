<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\PlantUML\PlantUmlRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class PlantUmlRendererTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testTheDiagramGoesThroughStdinAtTheAskedResolution(): void
    {
        $this->tool = new FakeTool('cat');

        $image = new PlantUmlRenderer(new Tool('plantuml', $this->tool->path()))->render("@startuml\n@enduml", Format::Png, new RenderOptions(dpi: 192));

        $this->assertSame("@startuml\n@enduml", $image->bytes);
        $this->assertSame(['-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi=192'], $this->tool->argv());
        $this->assertTrue(new PlantUmlRenderer()->supports(Format::Svg));
    }

    public function testTheDefaultResolutionIsPlantUmlsOwn(): void
    {
        $this->tool = new FakeTool('cat');

        new PlantUmlRenderer(new Tool('plantuml', $this->tool->path()))->render('x', Format::Png);

        $this->assertSame('-Sdpi=96', $this->tool->argv()[5]);
    }

    public function testAMissingToolSaysHowToFixIt(): void
    {
        $this->expectExceptionObject(new RenderFailure('plantuml not found: install it, or configure the path to plantuml or its .jar'));

        new PlantUmlRenderer(new Tool('viz-no-such-plantuml'))->render('x', Format::Svg);
    }
}
