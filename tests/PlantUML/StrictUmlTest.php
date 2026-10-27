<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class StrictUmlTest extends TestCase
{
    public function testTheStrictUmlStyleIsDrawnAsTheJarDrawsIt(): void
    {
        // As the jar sizes them: no footbox, no circled character, square containers, the rest as without it.
        $this->assertSame([[104.0, 203.0], [154.0, 193.0], [218.0, 119.0], [123.0, 149.0], [120.0, 293.0]], [
            self::measured(self::strict("actor U\nU -> B : hi\nB --> U : back\nB ->> U : async")),
            self::measured(self::strict("package P {\n class A\n}\nobject O\nnote \"n\" as N\nA --> O\nnote right of A : side\nA ..> N")),
            self::measured(self::strict("package P {\n [C]\n}\nrectangle R {\n [D]\n}")),
            self::measured(self::strict("class A\nnote right of A : side\nnote \"f\" as N\nA .. N")),
            self::measured(self::strict("start\n:a;\nif (x) then\n :b;\nendif\nstop")),
        ]);
    }

    public function testFilledHeadsArePlainTriangles(): void
    {
        foreach (["A -> B\nA <- B\nA -> A", "start\n:a;\n:b;"] as $source) {
            $heads = static fn (Drawing $drawing): array => \array_values(\array_filter(
                $drawing->marks,
                static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && \count($mark->points) <= 4 && '#000000' !== $mark->fill && 'none' !== $mark->fill && $mark->fill === $mark->stroke,
            ));
            $this->assertNotSame([], $heads(self::drawn($source)));
            $this->assertSame([3], \array_values(\array_unique(\array_map(static fn (Mark $mark): int => \count($mark->points), $heads(self::strict($source))))));
        }
    }

    public function testAnObjectsNameIsUnderlined(): void
    {
        $lines = static fn (Drawing $drawing): int => \count(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));

        // A linked name, already underlined, underlined again, as the jar decorates its text.
        $this->assertSame($lines(self::drawn("object O\nobject \"[[u x]]\" as L\nclass C")) + 2, $lines(self::strict("object O\nobject \"[[u x]]\" as L\nclass C")));
    }

    private static function strict(string $source): Drawing
    {
        return self::drawn("skinparam style strictuml\n{$source}");
    }

    private static function drawn(string $source): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n{$source}\n@enduml"));
    }

    /** @return array{float, float} */
    private static function measured(Drawing $drawing): array
    {
        return [\round($drawing->width / Units::POINT, precision: 6), \round($drawing->height / Units::POINT, precision: 6)];
    }
}
