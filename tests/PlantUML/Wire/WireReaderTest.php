<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Wire;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Wire\PlantUmlWire;
use Janalis\Viz\PlantUML\Wire\WireLink;
use Janalis\Viz\PlantUML\Wire\WireReader;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WireReaderTest extends TestCase
{
    public function testBlocksStackDownTheirParentAndAColumnStartsPastThem(): void
    {
        $root = self::read("* first\n* second [120x50]\n--\n* third")->root;

        $this->assertSame([[10.0, 20.0], [10.0, 140.0], [150.0, 20.0]], \array_map(static fn ($block): array => $block->absolute(), $root->children));
        $this->assertSame([[100.0, 100.0], [120.0, 50.0], [100.0, 100.0]], \array_map(static fn ($block): array => $block->size(), $root->children));
        $this->assertSame([260.0, 200.0], $root->size());
    }

    public function testATabOrFourSpacesIndentALevelIntoTheLastBlock(): void
    {
        $outer = self::read("* outer\n\t* a [60x30]\n    * b [60x30]\n* other")->root->children[0];

        $this->assertSame(['a', 'b'], \array_map(static fn ($block): string => $block->name, $outer->children));
        $this->assertSame([[20.0, 40.0], [20.0, 90.0]], \array_map(static fn ($block): array => $block->absolute(), $outer->children));
        // A block with blocks and no size of its own takes the room they need, 10 past them.
        $this->assertSame([80.0, 110.0], $outer->size());
    }

    public function testAMoveKeepsTheNextBlockBelowTheLastAndAGotoDoesNot(): void
    {
        $root = self::read("* a [50x40]\nmove(0, 60)\n* b [50x40]\ngoto(300, 10)\n* c [50x40]")->root;

        $this->assertSame([[10.0, 20.0], [10.0, 140.0], [300.0, 20.0]], \array_map(static fn ($block): array => $block->absolute(), $root->children));
    }

    public function testPrintedLinesMoveTheCursorALineEach(): void
    {
        $board = self::read("* board\n\tprint(\"one\\ntwo\")\n\tprint(\"\")\n\t* chip")->root->children[0];

        $this->assertSame([[10.0, 10.0, ['one', 'two']], [10.0, 10.0 + (2 * WireReader::LINE), ['']]], $board->prints);
        $this->assertEqualsWithDelta([20.0, 40.0 + (3 * WireReader::LINE)], $board->children[0]->absolute(), 1e-9);
    }

    public function testSpotsTakeAShareOfTheirBlockAndAnOffset(): void
    {
        $diagram = self::read("* board [200x100]\n\t* chip [40x20]\nspot board.chip(100%, 100%-5) #green\nspot board");
        [$chip, $board] = $diagram->spots;

        $this->assertSame([[60.0, 55.0], '#008000'], [$chip->block->absolute($chip->x, $chip->y), $chip->color]);
        $this->assertSame([[10.0, 20.0], '#ff0000'], [$board->block->absolute($board->x, $board->y), $board->color]);
    }

    public function testLinksLeaveTheirBlockOneAfterAnotherAndReachTheNearSideOfTheOther(): void
    {
        $links = self::read("* a [100x100]\n--\n* b [100x100]\na -> b : one\na => b\na -> b\na --> b\na(50, 100%) <== b #blue")->links;

        $this->assertSame(
            [[[110.0, 25.0], 130.0, false, false], [[110.0, 40.0], 130.0, false, true], [[110.0, 65.0], 130.0, false, false], [[15.0, 120.0], 20.0, true, false], [[60.0, 120.0], 20.0, true, true]],
            \array_map(static fn (WireLink $link): array => [$link->start, $link->destination, $link->vertical, $link->bus], $links),
        );
        $this->assertSame([['one'], null], [$links[0]->label, $links[1]->label]);
        $this->assertSame([false, true, '#0000ff'], [$links[4]->forward, $links[4]->backward, $links[4]->color]);
    }

    public function testALinkDownWritesNoText(): void
    {
        $drawing = Work::run(self::read("* a\n* b\na --> b : hidden\na -> b : shown")->drawing());
        $texts = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind && \in_array($mark->text, ['hidden', 'shown'], strict: true)));

        $this->assertSame(['shown'], \array_map(static fn ($mark): string => $mark->text, $texts));
    }

    public function testTheModelJoinsBlocksByTheirLinks(): void
    {
        $graph = self::read("* board\n\t* chip\n* far\nboard.chip <-> far : bus")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(
            [
                ['board',      'board'],
                ['board.chip', 'chip'],
                ['far',        'far'],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes),
        );
        $this->assertSame([['board.chip', 'far', 'bus']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->label], $graph->edges));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'a block twice' => ["* a\n* a", 'Component exists already'];
        yield 'an indented block under none' => ["\t* a", 'No block to indent under'];
        yield 'an indented spot' => ["* a\n  spot a", 'Syntax Error?'];
        yield 'a link to nothing' => ["* a\na -> b", 'No such element b'];
        yield 'an unknown colour' => ['* a #nosuchcolour', 'No such color'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startwire\n".$lines."\n@endwire"));
    }

    private static function read(string $lines): PlantUmlWire
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startwire\n".$lines."\n@endwire");
        self::assertInstanceOf(PlantUmlWire::class, $diagram);

        return $diagram;
    }
}
