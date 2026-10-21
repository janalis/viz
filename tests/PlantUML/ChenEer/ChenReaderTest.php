<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\ChenEer;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\PlantUML\ChenEer\ChenElement;
use Janalis\Viz\PlantUML\ChenEer\ChenKind;
use Janalis\Viz\PlantUML\ChenEer\PlantUmlChen;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChenReaderTest extends TestCase
{
    public function testAttributesHangFromTheirOwnersFromAbove(): void
    {
        $diagram = self::read(<<<'PUML'
            left to right direction
            entity "Shown" as E <<weak>> #pink {
              Id <<key>>
              Name {
                First #red
              }
              "Born on" as Born
            }
            PUML);

        $this->assertSame(Direction::LeftRight, $diagram->direction);
        $this->assertSame(['E', 'E/Id', 'E/Name', 'E/Name/First', 'E/Born'], \array_keys($diagram->elements));
        $entity = $diagram->elements['E'];
        $this->assertSame([ChenKind::Entity, ['Shown'], ['weak'], '#ffc0cb'], [$entity->kind, $entity->display, $entity->stereotypes, $entity->back]);
        $this->assertSame(['Born on'], $diagram->elements['E/Born']->display);
        $this->assertSame('#ff0000', $diagram->elements['E/Name/First']->line);
        $this->assertSame(
            [
                ['E/Id',         'E',      2],
                ['E/Name',       'E',      2],
                ['E/Name/First', 'E/Name', 2],
                ['E/Born',       'E',      2],
            ],
            \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to, $link->length], $diagram->links),
        );
    }

    public function testAssociationsAndSubclassesSpanTwoRanks(): void
    {
        $diagram = self::read(<<<'PUML'
            entity A {
            }
            relationship R {
            }
            entity B {
            }
            entity C {
            }
            A -(1,N)- R
            R =N= B #blue
            A ->- B
            B -<- C
            A =>= U { B, C }
            PUML);

        $this->assertSame(
            [
                ['A',                'R',                3, false, '(1,N)'],
                ['R',                'B',                3, true,  'N'],
                ['A',                'B',                3, false, null],
                ['B',                'C',                3, false, null],
                ['A',                'A/U B, C /center', 2, true,  null],
                ['A/U B, C /center', 'B',                3, false, null],
                ['A/U B, C /center', 'C',                3, false, null],
            ],
            \array_map(static fn (PlantUmlLink $link): array => [$link->from, $link->to, $link->length, $link->bold, $link->label], $diagram->links),
        );
        $this->assertSame('#0000ff', $diagram->links[1]->color);
        // A superset sign across `->-` and the union's link to its superclass, a subset one across `-<-`.
        $this->assertSame([2 => true, 3 => false, 4 => true], $diagram->signs);
        $this->assertSame([ChenKind::Circle, ['U']], [$diagram->elements['A/U B, C /center']->kind, $diagram->elements['A/U B, C /center']->display]);
    }

    public function testADisjointCircleSignsItsSubclassesLinks(): void
    {
        // As PlantUML's id, the subclasses as written, spaces and all.
        $diagram = self::read("entity A {\n}\nentity B {\n}\nA ->- d {B}");

        $this->assertSame([1 => true], $diagram->signs);
        $this->assertSame(['d'], $diagram->elements['A/dB/center']->display ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'attribute outside' => ['Name', 'Attribute must be inside an entity, relationship or another attribute'];
        yield 'attribute twice' => ["entity A {\nx\nx\n}", 'Attribute already exists'];
        yield 'unknown entity' => ["entity A {\n}\nA -1- B", 'No such entity: B'];
        yield 'unbalanced' => ['}', 'Unbalanced brackets'];
        yield 'renamed kind' => ["entity A {\n}\nrelationship A {\n}", 'Bad name'];
        yield 'not drawn' => ["entity A {\n}\nset separator ::", 'The native PlantUML renderer does not draw: set separator ::'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFails(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        PlantUmlPipeline::native()->diagram("@startchen\n".$lines."\n@endchen");
    }

    public function testEachShapeIsSizedAsPlantUmlSizesIt(): void
    {
        $drawing = Work::run(self::read("entity E {\n}\nrelationship R {\n}\nentity F {\nA\n}\nE ->- d { F }")->drawing());
        $sizes = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Polygon !== $mark->kind) {
                continue;
            }
            $xs = \array_column($mark->points, 0);
            $ys = \array_column($mark->points, 1);
            $sizes[] = [\round((\max($xs) - \min($xs)) / Units::POINT, precision: 2), \round((\max($ys) - \min($ys)) / Units::POINT, precision: 2)];
        }
        // A relationship's diamond twice as wide as tall, and the subset circle 25 across.
        $this->assertContains([25.0, 25.0], $sizes);
        $this->assertNotEmpty(\array_filter($sizes, static fn (array $size): bool => \abs($size[0] - (2 * $size[1])) < 0.01 && $size[0] > 25));
    }

    public function testLinksStopAtTheOutlinesOfOvalsAndDiamonds(): void
    {
        $drawing = Work::run(self::read("entity E {\nAttribute\n}")->drawing());
        $lines = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind));
        $ovals = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind && \count($mark->points) > 4));

        $this->assertCount(1, $lines);
        $this->assertCount(1, $ovals);
        $bottom = \max(\array_column($ovals[0]->points, 1));
        // From the bottom of the ellipse, not the bottom of its box: below its centre, within a hair of its outline.
        $this->assertEqualsWithDelta($bottom, $lines[0]->points[0][1], 0.5 * Units::POINT);
    }

    public function testTheModelKeepsShapesBoldLinesAndCardinalities(): void
    {
        $graph = self::read("title ER\nentity A {\nx\n}\nrelationship R {\n}\nA =1= R")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame('ER', $graph->title);
        $this->assertSame(
            [
                ['n0', 'A', Shape::Box],
                ['n1', 'x', Shape::Ellipse],
                ['n2', 'R', Shape::Diamond],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->label, $node->shape], $graph->nodes),
        );
        $this->assertSame(
            [
                ['n1', 'n0', '',  LineStyle::Solid],
                ['n0', 'n2', '1', LineStyle::Bold],
            ],
            \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->label, $edge->style], $graph->edges),
        );
    }

    public function testStereotypesAreReadFromTheirMarks(): void
    {
        $element = new ChenElement('a', ChenKind::Attribute, ['a'], ['key', 'multi']);

        $this->assertTrue($element->has('multi'));
        $this->assertFalse($element->has('derived'));
    }

    private static function read(string $lines): PlantUmlChen
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startchen\n".$lines."\n@endchen");
        self::assertInstanceOf(PlantUmlChen::class, $diagram);

        return $diagram;
    }
}
