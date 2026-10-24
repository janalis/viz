<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Wbs;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Wbs\PlantUmlWbs;
use Janalis\Viz\PlantUML\Wbs\WbsElement;
use Janalis\Viz\PlantUML\Wbs\WbsShape;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbsReaderTest extends TestCase
{
    public function testElementsStandUnderTheLastOfTheDepthAbove(): void
    {
        $root = self::read(<<<'PUML'
            * Root
            ** One <<rose>>
            ***[#pink]_ Leaf
            ***< Left
            ** Two\nlines
            ++- Minus
            PUML)->root;

        $this->assertSame([['One'], ['Two', 'lines']], self::labels($root->right));
        $one = $root->right[0];
        $this->assertSame('<<rose>>', $one->stereotype);
        $this->assertSame(
            [['Leaf', 2, WbsShape::None, '#pink']],
            \array_map(static fn (WbsElement $element): array => [$element->label[0], $element->level, $element->shape, $element->color], $one->right),
        );
        $this->assertSame([['Left']], self::labels($one->left));
        $this->assertSame([['Minus']], self::labels($root->right[1]->left));
    }

    public function testARootChildOnTheLeftAlsoStandsFirstOnTheRight(): void
    {
        $root = self::read("* Root\n** a\n**< b\n--- c")->root;

        $this->assertSame([['b'], ['a']], self::labels($root->right));
        $this->assertSame([['b']], self::labels($root->left));
        $this->assertSame([['c']], self::labels($root->right[0]->left));
    }

    public function testDepthsAreMeasuredAgainstTheRootsMarks(): void
    {
        $root = self::read("* Root\n * One\n  * Deep\n\t* Two\n**_")->root;

        $this->assertSame([['One'], ['Two'], ['']], self::labels($root->right));
        $this->assertSame([['Deep']], self::labels($root->right[0]->right));
        $this->assertSame(WbsShape::Pseudo, $root->right[2]->shape);
    }

    public function testMultilineElementsTakeTheirStereotypeAfterTheirEnd(): void
    {
        $element = self::read(<<<'PUML'
            * Root
            **[#lightblue]_:a
            ' a comment
            b; <<rose>>
            PUML)->root->right[0];

        $this->assertSame([['a', 'b'], '<<rose>>', WbsShape::None, '#lightblue'], [$element->label, $element->stereotype, $element->shape, $element->color]);
    }

    public function testArrowsJoinElementsByTheirCodes(): void
    {
        $diagram = self::read("* Root\n**(a) A\n** \"B\" as b\n***(c)[#pink] C\na -> b\nb ..> c #blue <<x>>");

        $this->assertSame(
            [
                ['A', 'B', null,    null],
                ['B', 'C', '#blue', '<<x>>'],
            ],
            \array_map(static fn (array $link): array => [$link[0]->label[0], $link[1]->label[0], $link[2], $link[3]], $diagram->links),
        );
        $this->assertSame('#pink', $diagram->root->right[1]->right[0]->color);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'empty' => ['', 'Empty description'];
        yield 'two roots' => ["* a\n* b", 'WBS diagram only accepts one root first declared.'];
        yield 'too deep' => ["* a\n*** b", 'Bad tree structure'];
        yield 'no label' => ["* a\n**", 'Missing label for WBS node.'];
        yield 'unknown code' => ["* a\n**(b) b\nb -> c", 'No such node c'];
        yield 'an arrow to itself, which PlantUML fails on' => ["* a\n**(b) b\nb -> b", 'an arrow from an element to itself: b -> b'];
        yield 'unreadable indentation' => ["* a\n ++ b", 'type=< ++>[*]'];
        yield 'not drawn' => ["* a\nset separator ::", 'The native PlantUML renderer does not draw: set separator ::'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFails(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        PlantUmlPipeline::native()->diagram("@startwbs\n".$lines."\n@endwbs");
    }

    public function testTheModelJoinsEachElementToItsParent(): void
    {
        $graph = self::read("title Work\n*[#pink] Root\n**< a\n***_ b\n**(c) c\n***(d) d\nc -> d")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['Work', Direction::TopBottom], [$graph->title, $graph->direction]);
        $this->assertSame(
            [
                ['n0', 'Root', '#ffc0cb', null],
                ['n1', 'a',    null,      null],
                ['n2', 'b',    null,      'none'],
                ['n3', 'c',    null,      null],
                ['n4', 'd',    null,      null],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->label, $node->fill, $node->stroke], $graph->nodes),
        );
        $this->assertSame(
            [
                ['n0', 'n1', ArrowHead::None],
                ['n1', 'n2', ArrowHead::None],
                ['n0', 'n3', ArrowHead::None],
                ['n3', 'n4', ArrowHead::None],
                ['n3', 'n4', ArrowHead::Arrow],
            ],
            \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->head], $graph->edges),
        );
    }

    public function testALoneRootHangsALineUnderIt(): void
    {
        $drawing = Work::run(self::read('* alone')->drawing());

        $this->assertSame([MarkKind::Polygon, MarkKind::Text, MarkKind::Polyline], \array_map(static fn ($mark): MarkKind => $mark->kind, $drawing->marks));
        $ys = \array_column($drawing->marks[2]->points, 1);
        $this->assertEqualsWithDelta(20.0, (\max($ys) - \min($ys)) / Units::POINT, 0.001);
    }

    public function testAnArrowEndsInAHeadAtTheEdgeOfItsTarget(): void
    {
        $drawing = Work::run(self::read("* Root\n**(a) A\n**(b) B\na -> b #red")->drawing());
        $red = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => '#ff0000' === $mark->stroke));

        $this->assertSame([MarkKind::Polyline, MarkKind::Polygon], \array_map(static fn ($mark): MarkKind => $mark->kind, $red));
        // Side by side, the arrow runs straight across, from one's right side to the other's left.
        $this->assertEqualsWithDelta($red[0]->points[0][1], $red[0]->points[1][1], 0.001);
        $this->assertEquals($red[0]->points[1], $red[1]->points[0]);
    }

    public function testATransparentArrowStyleDrawsNoLines(): void
    {
        $drawing = Work::run(self::read("<style>\nwbsDiagram {\n arrow {\n  LineColor transparent\n }\n}\n</style>\n* a\n** b\n** c")->drawing());

        $this->assertSame([], \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind));
    }

    public function testATransparentArrowIsNotDrawn(): void
    {
        $red = Work::run(self::read("* Root\n**(a) A\n**(b) B\na -> b #red")->drawing());
        $transparent = Work::run(self::read("* Root\n**(a) A\n**(b) B\na -> b #transparent")->drawing());

        // As PlantUML strokes it with none: neither its line nor its head shows.
        $this->assertCount(\count($red->marks) - 2, $transparent->marks);
        $this->assertSame([], \array_filter($transparent->marks, static fn ($mark): bool => 'none' === $mark->stroke));
    }

    /**
     * @param list<WbsElement> $elements
     * @return list<list<string>>
     */
    private static function labels(array $elements): array
    {
        return \array_map(static fn (WbsElement $element): array => $element->label, $elements);
    }

    private static function read(string $lines): PlantUmlWbs
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startwbs\n".$lines."\n@endwbs");
        self::assertInstanceOf(PlantUmlWbs::class, $diagram);

        return $diagram;
    }
}
