<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\PlantUML\Source\PlantUmlClassWriter;
use Janalis\Viz\Tests\Support\ClassSample;
use PHPUnit\Framework\TestCase;

final class PlantUmlClassWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenAsAPlantUmlClassDiagram(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Classes
            left to right direction
            package "Shapes" {
              package "shapes.base" {
                abstract class Base {
                  id
                }
              }
              interface Shape<T> {
                +name : String
                {static} +area() : double
                {abstract} -resize(int factor) : void
              }
              class "Round 'thing'" as Circle <<round>>
            }
            enum Color {
              RED
              GREEN
            }
            annotation Marker
            entity Row
            object it
            class "two words" as c0
            Shape <|.. Circle : implements
            Circle "1" *--> "many" c0 : logs\nmuch
            Base o-- Color
            Row x--|> it
            Marker <..* Row
            Color --o Base
            it --x Row
            note "round\nand round" as N0
            N0 .. Circle
            note "free 'note'" as N1
            @enduml

            PUML, PlantUmlClassWriter::write(ClassSample::diagram()));
    }
}
