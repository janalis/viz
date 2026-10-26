<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\MindMaps;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\MindMaps\MindMapIdea;
use Janalis\Viz\PlantUML\MindMaps\PlantUmlMindMap;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MindMapReaderTest extends TestCase
{
    public function testIdeasStandUnderTheLastOfTheDepthAbove(): void
    {
        $diagram = self::read(<<<'PUML'
            * Root
            ** One <<rose>>
            ***[#pink]_ Leaf
            ** Two\nlines
            -- Left
            --- Further
            PUML);

        $this->assertCount(1, $diagram->maps);
        $map = $diagram->maps[0];
        $this->assertSame([['One '], ['Two', 'lines']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $map->right->children));
        $this->assertSame('<<rose>>', $map->right->children[0]->stereotype);
        $leaf = $map->right->children[0]->children[0];
        $this->assertSame([['Leaf'], 2, false, '#pink'], [$leaf->label, $leaf->level, $leaf->boxed, $leaf->color]);
        $this->assertSame([['Left']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $map->left->children));
        $this->assertSame(['Further'], $map->left->children[0]->children[0]->label);
    }

    public function testDepthsCountInStepsOfTheFirst(): void
    {
        $map = self::read(<<<'PUML'
            * root
              * first
                * second
              * third
            		* tabbed
            PUML)->maps[0];

        $this->assertSame([['first'], ['third'], ['tabbed']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $map->right->children));
        $this->assertSame(['second'], $map->right->children[0]->children[0]->label);
    }

    public function testAnIndentedRootTakesTheDepthOfItsMarks(): void
    {
        $map = self::read("  * root\n    ** a\n  *** b\n * c")->maps[0];

        $this->assertSame([['a'], ['c']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $map->right->children));
        $this->assertSame(['b'], $map->right->children[0]->children[0]->label);
    }

    public function testMultilineIdeasTakeTheirStereotypeAfterTheirEnd(): void
    {
        $map = self::read(<<<'PUML'
            *:Root
            on two;
            **[#lightblue]_:a
            ' a comment
            b; <<rose>>
            PUML)->maps[0];

        $this->assertSame(['Root', 'on two'], $map->right->label);
        $idea = $map->right->children[0];
        $this->assertSame([['a', 'b'], '<<rose>>', false, '#lightblue'], [$idea->label, $idea->stereotype, $idea->boxed, $idea->color]);
    }

    public function testSidesDirectionsAndRoots(): void
    {
        $diagram = self::read(<<<'PUML'
            top to bottom direction
            left side
            0 First
            ** above
            right side
            ** below
            + Second
            ++ right
            PUML);

        $this->assertSame(Direction::TopBottom, $diagram->direction);
        $this->assertCount(2, $diagram->maps);
        $this->assertSame([['above']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $diagram->maps[0]->left->children));
        $this->assertSame([['below']], \array_map(static fn (MindMapIdea $idea): array => $idea->label, $diagram->maps[0]->right->children));
        $this->assertSame(['Second'], $diagram->maps[1]->right->label);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'empty' => ['', 'Empty description'];
        yield 'not a step' => ["* a\n*** b\n** c", 'Bad indentation'];
        yield 'too deep' => ["* a\n** b\n**** c", 'error42L'];
        yield 'indented plus' => ["* a\n  ++ b", 'Syntax Error?'];
        yield 'unreadable indentation' => ["* a\n *# b", 'type=< *#>[*]'];
        yield 'unknown command' => ["* a\nskinparam shadowing true\nfoo", 'No PlantUML diagram reads this source'];
        yield 'not drawn' => ["* a\nset separator ::", 'The native PlantUML renderer does not draw: set separator ::'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFails(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        PlantUmlPipeline::native()->diagram("@startmindmap\n".$lines."\n@endmindmap");
    }

    public function testTheModelJoinsEachIdeaToItsParent(): void
    {
        $graph = self::read("title Map\n*[#pink] Root\n** a\n***_ b\n-- c")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame('Map', $graph->title);
        $this->assertSame(
            [
                ['m0', 'Root', Shape::Rounded, '#ffc0cb', null],
                ['n1', 'a',    Shape::Rounded, null,      null],
                ['n2', 'b',    Shape::Box,     null,      'none'],
                ['n3', 'c',    Shape::Rounded, null,      null],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->label, $node->shape, $node->fill, $node->stroke], $graph->nodes),
        );
        $this->assertSame([['m0', 'n1'], ['n1', 'n2'], ['m0', 'n3']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    public function testFromTopToBottomTheRootsOnesStandBelowAndAbove(): void
    {
        $drawing = Work::run(self::read("top to bottom direction\n* Root\n** below\n-- above\n--- further")->drawing());
        $texts = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $texts[(string) $mark->text] = $mark->points[0][1] / Units::POINT;
        }

        $this->assertGreaterThan($texts['above'], $texts['Root']);
        $this->assertGreaterThan($texts['further'], $texts['above']);
        $this->assertGreaterThan($texts['Root'], $texts['below']);
    }

    public function testALoneRootIsDrawnWithTheRoomAfterIt(): void
    {
        $drawing = Work::run(self::read('* alone')->drawing());

        $this->assertCount(2, $drawing->marks);
        $xs = \array_column($drawing->marks[0]->points, 0);
        // Its margins, 30 more, the diagram's 10 and the frame's 10 on each side, in whole points rounded up, as PlantUML writes its size.
        $this->assertEqualsWithDelta(\ceil(((\max($xs) - \min($xs)) / Units::POINT) + 80), $drawing->width / Units::POINT, 0.001);
    }

    public function testATransparentArrowIsNotDrawn(): void
    {
        $drawing = Work::run(self::read("<style>\nmindmapDiagram {\n arrow {\n  LineColor transparent\n }\n}\n</style>\n* a\n** b")->drawing());

        $this->assertSame([], \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind && 'none' !== $mark->stroke && $mark->strokeWidth > 0));
    }

    private static function read(string $lines): PlantUmlMindMap
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startmindmap\n".$lines."\n@endmindmap");
        self::assertInstanceOf(PlantUmlMindMap::class, $diagram);

        return $diagram;
    }
}
