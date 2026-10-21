<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Timing\PlantUmlTiming;
use Janalis\Viz\PlantUML\Timing\TimingChange;
use Janalis\Viz\PlantUML\Timing\TimingRuler;
use Janalis\Viz\PlantUML\Timing\TimingTick;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimingReaderTest extends TestCase
{
    public function testStatesAreSetAtTheMomentAndBeforeAny(): void
    {
        $diagram = self::read("robust \"DNS\" as DNS\nDNS has Idle,Processing\nconcise \"Client\" as C\nC is Ready\n@0\nC is Busy\n@50\nDNS is Processing : lookup\nC is {...}");
        [$dns, $client] = \array_values($diagram->players);

        $this->assertSame(['Idle' => 'Idle', 'Processing' => 'Processing'], $dns->labels);
        $this->assertSame(['Ready'], $client->initial?->states);
        $this->assertSame([['Busy', 0.0], ['{...}', 50.0]], \array_map(static fn (TimingChange $change): array => [$change->state(), $change->when?->time], $client->changes()));
        $this->assertSame('lookup', $dns->changes()[0]->comment);
    }

    public function testMomentsAreNamedShiftedAndRelative(): void
    {
        $diagram = self::read("concise \"W\" as W\n@10 as :start\n@W\n+5 is A\n@:start+20\nW is B\nclock \"C\" as C with period 7\n@C*3\nW is D");

        $this->assertSame([15.0, 21.0, 30.0], \array_map(static fn (TimingChange $change): ?float => $change->when?->time, $diagram->players['W']->changes()));
        $this->assertSame(10.0, $diagram->codes['start']->time);
    }

    public function testABinaryPlayerIsHighOrLow(): void
    {
        $diagram = self::read("binary \"B\" as B\n@0\nB is high\n@5\nB is 0\n@10\nB is {0,1}");

        $this->assertSame([['1'], ['0'], ['0', '1']], \array_map(static fn (TimingChange $change): array => $change->states, $diagram->players['B']->changes()));
    }

    public function testTheTimeAxisTicksByTheHighestCommonFactorOfTheMoments(): void
    {
        $ruler = new TimingRuler();
        foreach ([0, 100, 300] as $time) {
            $ruler->add(new TimingTick($time));
        }

        $this->assertSame([100, 200.0, 150.0, 4], [$ruler->unit(), $ruler->width(), $ruler->x(300), $ruler->ticks()]);
        $ruler->add(new TimingTick(4001));
        // A factor of 1 that would take the diagram past 4000 wide gives way to one that does not.
        $this->assertSame(51, $ruler->unit());
    }

    public function testTimesAreWrittenAsTheirFormatAsks(): void
    {
        $this->assertSame(['25', '1:02:03', '03/15'], [
            TimingTick::written(25.0, 'decimal'),
            TimingTick::written(3723.0, 'hour'),
            TimingTick::written((float) \gmmktime(hour: 0, minute: 0, second: 0, month: 3, day: 15, year: 2024), 'date'),
        ]);
    }

    public function testAMessageIsAnArrowBetweenTheMoments(): void
    {
        $drawing = Work::run(self::read("concise \"A\" as A\nconcise \"B\" as B\n@0\nA is x\nB is y\n@50\nA -> B@+50 : hello\n@100\nB is z")->drawing());
        $texts = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && 'hello' === $mark->text));
        $heads = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 3 === \count($mark->points) && '#00008b' === $mark->fill);

        $this->assertCount(1, $texts);
        $this->assertCount(1, $heads);
    }

    public function testTheModelJoinsPlayersByTheirMessages(): void
    {
        $graph = self::read("robust \"Web\" as W\nconcise C\n@0\nW -> C : go")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['W', 'Web'], ['C', 'C']], \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes));
        $this->assertSame([['W', 'C', 'go']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->label], $graph->edges));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'a state before any player is set' => ["binary \"B\" as B\n@0\n10 is high", 'Missing @ line before this'];
        yield 'a clock set to a state' => ["clock \"C\" as C with period 10\nC is high", 'A clock has no states'];
        yield 'an unknown player' => ["concise \"W\" as W\nX is A", 'Unknown "X"'];
        yield 'states named on a binary player' => ["binary \"B\" as B\nB has a,b", 'A binary player has no states to name'];
        yield 'an unknown moment' => ["concise \"W\" as W\n@:nope\nW is A", 'What time?'];
        yield 'an analog player' => ["analog \"A\" as A\n@0\nA is 3", 'does not draw: analog'];
        yield 'a dashed message' => ["concise A\nconcise B\n@0\nA -[dashed]> B", 'styled dashed'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatItDoesNotDraw(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }

    private static function read(string $lines): PlantUmlTiming
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startuml\n".$lines."\n@enduml");
        self::assertInstanceOf(PlantUmlTiming::class, $diagram);

        return $diagram;
    }
}
