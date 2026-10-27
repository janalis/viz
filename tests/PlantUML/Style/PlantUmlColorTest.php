<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Style;

use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class PlantUmlColorTest extends TestCase
{
    public function testAColourWrittenOnAnElementIsOneThatPlantUmlKnows(): void
    {
        // As `ColorParser.simpleColor`: a name, a gradient, `transparent`, or parts left as written.
        $this->assertSame([null, '#red-blue', '#transparent', '#back:red;line:blue'], [
            PlantUmlColor::written(null),
            PlantUmlColor::written('#red-blue'),
            PlantUmlColor::written('#transparent'),
            PlantUmlColor::written('#back:red;line:blue'),
        ]);
    }

    public function testNoneAndUnknownColoursOnAnElementAreRefused(): void
    {
        foreach (['#none', '#nosuchcolour'] as $color) {
            try {
                PlantUmlColor::written($color);
                $this->fail($color);
            } catch (PlantUmlRefusal $refusal) {
                $this->assertSame('No such color', $refusal->getMessage());
            }
        }
    }

    public function testEachKindOfElementRefusesAColourPlantUmlDoesNotKnow(): void
    {
        // As the jar answers each: no such colour.
        foreach (['class A #none', '[A] #nosuch', 'state S #none', "package P #none {\nclass A\n}"] as $source) {
            try {
                Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"));
                $this->fail($source);
            } catch (Unsupported) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
