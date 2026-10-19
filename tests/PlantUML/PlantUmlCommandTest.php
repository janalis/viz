<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\PlantUML\PlantUmlCommand;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

/**
 * The shapes PlantUML arrives in. Pure, so the argv — including the one that
 * needs a JVM — is pinned without running anything.
 */
final class PlantUmlCommandTest extends TestCase
{
    public function testAnExecutableIsRunDirectly(): void
    {
        $this->assertSame(
            ['/opt/bin/plantuml', '-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi=96'],
            PlantUmlCommand::render('/opt/bin/plantuml', Format::Png),
        );
    }

    public function testAJarIsHandedToJava(): void
    {
        $this->assertSame(
            ['java', '-jar', '/opt/plantuml.jar', '-tsvg', '-pipe', '-failfast2', '-charset', 'UTF-8'],
            PlantUmlCommand::render('/opt/plantuml.jar', Format::Svg),
        );
    }

    public function testOnlyABitmapTakesAResolution(): void
    {
        $this->assertSame(['plantuml', '-tpdf', '-pipe', '-failfast2', '-charset', 'UTF-8'], PlantUmlCommand::render('plantuml', Format::Pdf, dpi: 300));
        $this->assertSame('-Sdpi=300', PlantUmlCommand::render('plantuml', Format::Png, dpi: 300)[6]);
    }
}
