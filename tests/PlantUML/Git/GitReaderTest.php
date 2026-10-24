<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Git;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Git\GitArea;
use Janalis\Viz\PlantUML\Git\GitNode;
use Janalis\Viz\PlantUML\Git\PlantUmlGit;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class GitReaderTest extends TestCase
{
    public function testACommitIsNamedByItsFirstWordAndCommentedBetweenBrackets(): void
    {
        $area = new GitArea(['*   d5e6f7a (HEAD -> master) Merge', '|\  ', '| * c4d5e6f Add', '* | a2b3c4d Fix', '|/  ', '* 91a2b3c Initial']);

        $this->assertSame(
            [
                ['d5e6f7a', 'HEAD -> master', 0, 0],
                ['c4d5e6f', null,             2, 2],
                ['a2b3c4d', null,             0, 3],
                ['91a2b3c', null,             0, 5],
            ],
            $area->commits(),
        );
        // Straight down first, then along the branch.
        $this->assertSame(['a2b3c4d', 'c4d5e6f'], $area->parents(0, 0));
        $this->assertSame(['91a2b3c'], $area->parents(2, 2));
    }

    public function testARunOfCommitsWithOneParentAndOneChildIsOneNode(): void
    {
        $git = self::read("*   m (HEAD)\n|\\  \n| * c\n| * b\n* | a\n|/  \n* r");

        $this->assertSame([['m'], ['c', 'b'], ['a'], ['r']], \array_map(static fn (GitNode $node): array => $node->texts, $git->nodes));
        $this->assertSame([true, false], [$git->nodes[0]->top(), $git->nodes[1]->top()]);
    }

    public function testOnlyATopNodeShowsItsComment(): void
    {
        $drawing = Work::run(self::read("* b (HEAD -> main) Two\n* a (tag: v1) One")->drawing());
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));

        $this->assertSame(['HEAD -> main', 'b', 'a'], $texts);
    }

    public function testTheModelJoinsEachCommitToItsParents(): void
    {
        $graph = self::read("* b\n* a")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['b', 'b'], ['a', 'a']], \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes));
        $this->assertSame([['b', 'a']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    public function testACommitRightBelowAnotherIsItsParent(): void
    {
        $graph = self::read("* b\n* a")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['b', 'a']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    public function testAnOctopusMergeLeadsDownEachOfItsDotsToo(): void
    {
        $graph = self::read("*-.   m\n|\\ \\  \n| | * c\n| * b\n* a")->model();

        // As the jar draws it: an arrow from m to each of a, b and c.
        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['m', 'a'], ['m', 'b'], ['m', 'c']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    public function testALogIsSizedAsPlantUmlSizesIt(): void
    {
        $size = static function (string $log): array {
            $drawing = Work::run(self::read($log)->drawing());

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sizes them: rounded down to whole points, and 1 more.
        $this->assertSame([[38.0, 106.0], [73.0, 122.0]], [$size("* a\n* b"), $size("* a (c)\n| * b\n|/\n* c")]);
    }

    public function testALogWithNoCommitIsAPointInItsMargin(): void
    {
        $drawing = Work::run(self::read('| x')->drawing());

        // As the jar sizes it: 21 by 21.
        $this->assertSame([21.0, 21.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
    }

    public function testAGraphLeadingNowhereIsRefused(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('PlantUML does not read this git log');

        Work::run(PlantUmlPipeline::native()->drawing("@startgit\n* a\n|\\\n| * b\n@endgit"));
    }

    private static function read(string $lines): PlantUmlGit
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startgit\n".$lines."\n@endgit");
        self::assertInstanceOf(PlantUmlGit::class, $diagram);

        return $diagram;
    }
}
