<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Timing;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimingDrawingTest extends TestCase
{
    /** @return iterable<string, array{string, array{float, float}}> */
    public static function sizes(): iterable
    {
        yield 'a comment on a change' => ["concise \"W\" as W\n@0\nW is A : first\n@10\nW is B", [151.0, 124.0]];
        yield 'a rectangle player, its first state open to the left' => ["rectangle \"R\" as R\nR is A\n@0\nR is B\n@10\nR is C", [185.0, 110.0]];
        yield 'a hidden stretch of a robust player' => ["robust \"W\" as W\n@0\nW is A\n@10\nW is {hidden}\n@20\nW is B", [209.0, 119.0]];
        yield 'a message to a robust player that never changes' => ["robust \"R\" as R\nconcise \"C\" as C\n@0\nC is x\n@10\nC -> R", [151.0, 155.0]];
        yield 'messages to a robust player between and after its changes' => ["robust \"R\" as R\nconcise \"C\" as C\n@0\nR is A\nC is x\n@10\nC -> R\n@20\nR is B\n@30\nC -> R", [259.0, 175.0]];
        yield 'a robust player of a height in pixels' => ["robust \"R\" as R\nR has A,B,C\nR is 100 pixels height\n@0\nR is A\n@10\nR is C", [159.0, 199.0]];
        yield 'a constraint over a robust player\'s change' => ["robust \"R\" as R\n@0\nR is A\n@10\nR is B\n@20\nR is A\nR@0 <-> @20 : span", [209.0, 128.0]];
        yield 'a binary player\'s comment, constraint and message' => ["binary \"B\" as B\nconcise \"C\" as C\n@0\nB is high : up\nC is x\n@10\nB is low\nC -> B\nB@0 <-> @10 : span", [156.0, 177.0]];
        yield 'a clock starting past the axis' => ["clock \"K\" as K with period 10 pulse 5 offset 1000\nconcise \"C\" as C\n@0\nC is x\n@20\nC is y", [206.0, 157.0]];
        yield 'messages to a clock before and after its offset' => ["clock \"K\" as K with period 10 pulse 5 offset 5\nconcise \"C\" as C\n@0\nC is x\nC -> K\n@7\nC -> K", [606.0, 157.0]];
        yield 'a player with no title' => ["concise \" \" as W\n@0\nW is A", [101.0, 93.0]];
        yield 'a scale whose ticks span 0' => ["scale 10 as 50 pixels\nconcise \"W\" as W\n@-15\nW is A\n@25\nW is B", [301.0, 110.0]];
        yield 'a second state at the same moment' => ["concise \"W\" as W\n@0\nW is A\nW is B", [101.0, 110.0]];
    }

    /** @param array{float, float} $size */
    #[DataProvider('sizes')]
    public function testItIsAsLargeAsPlantUmlDrawsIt(string $lines, array $size): void
    {
        $drawing = self::draw($lines);

        // As the jar sizes them.
        $this->assertEqualsWithDelta($size, [$drawing->width / Units::POINT, $drawing->height / Units::POINT], 1e-9);
    }

    public function testAStateSetBeforeAnyMomentRunsTheWholeAxis(): void
    {
        $ribbon = self::draw("concise \"W\" as W\nW is Idle");
        $flat = self::draw("concise \"W\" as W\nW is {-}");

        $this->assertCount(1, \array_filter($ribbon->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 4 === \count($mark->points)), 'a ribbon, its edges above and below');
        $this->assertContains('Idle', \array_map(static fn (Mark $mark): string => $mark->text, $ribbon->marks));
        $this->assertSame([], \array_filter($flat->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind), 'a flat line, no ribbon');
    }

    public function testAMessageAtNoMomentIsNotDrawn(): void
    {
        $drawing = self::draw("concise \"A\" as A\nconcise \"B\" as B\nA -> B");

        $this->assertSame([], \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 3 === \count($mark->points)), 'no arrowhead');
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }
}
