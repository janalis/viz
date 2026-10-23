<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Chart;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Chart\ChartKind;
use Janalis\Viz\PlantUML\Chart\ChartSeries;
use Janalis\Viz\PlantUML\Chart\ChartText;
use Janalis\Viz\PlantUML\Chart\PlantUmlChart;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChartReaderTest extends TestCase
{
    public function testTheChartIsSizedAsPlantUmlSizesIt(): void
    {
        $size = static function (string $source): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing($source));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sizes it: rounded down to whole points, and 1 more.
        $this->assertSame([501.0, 431.0], $size("@startchart\nh-axis [Q1, Q2, Q3, Q4]\nv-axis 0 --> 100\nbar \"Revenue\" [40, 55, 70, 85]\nline \"Target\" [50, 60, 65, 80]\n@endchart"));
    }

    public function testAnAxisStretchesToItsValuesUntilARangeIsGiven(): void
    {
        $chart = self::read("h-axis [a, b]\nbar [-20, 150]\nv2-axis 0 --> 10\nline [5, 50] v2");

        $this->assertSame([-20.0, 150.0, true], [$chart->yAxis->min, $chart->yAxis->max, $chart->yAxis->auto]);
        $this->assertSame([0.0, 10.0, false], [$chart->y2Axis?->min, $chart->y2Axis?->max, $chart->y2Axis?->auto]);
    }

    public function testSeriesAreNamedByTheirKindAndPlace(): void
    {
        $chart = self::read("h-axis [a, b]\nbar [1, 2] #red labels\nline <<target>> [3, 4] y2\nscatter \"S\" [1, 2] <<triangle>>");

        $this->assertSame(
            [
                ['bar0',  ChartKind::Bar,     '#ff0000', true],
                ['line1', ChartKind::Line,    null,      false],
                ['S',     ChartKind::Scatter, null,      false],
            ],
            \array_map(static fn (ChartSeries $series): array => [$series->name, $series->kind, $series->color, $series->labels], $chart->series),
        );
        $this->assertSame([['target'], true, 'triangle'], [$chart->series[1]->stereotypes, $chart->series[1]->secondary, $chart->series[2]->marker]);
    }

    public function testPairsPlaceValuesAcrossTheRange(): void
    {
        $chart = self::read("h-axis \"x\" -5 --> 5\nline [(-4, 16), (0,0) ,(4,16)]");

        $this->assertSame([[-4.0, 0.0, 4.0], [16.0, 0.0, 16.0]], [$chart->series[0]->xs, $chart->series[0]->values]);
        $this->assertSame('x', $chart->xAxis->title);
    }

    public function testNamedTicksKeepTheirOrder(): void
    {
        $chart = self::read('v-axis 0 --> 100 ticks [50:"Mid", 0:"Low", 50:"Half"] spacing 5 label-top grid');

        $this->assertSame([[50.0, 'Half'], [0.0, 'Low']], $chart->yAxis->ticks);
        $this->assertSame([5.0, true, true], [$chart->yAxis->spacing, $chart->yAxis->top, $chart->yGrid]);
    }

    public function testValuesAreWrittenAsPlantUmlWritesThem(): void
    {
        $this->assertSame(['5', '-3', '12.50', '12.5', '5.00e-03', '0'], [
            ChartText::value(5.0),
            ChartText::value(-3.0),
            ChartText::value(12.5),
            ChartText::value(12.5, 1),
            ChartText::value(0.005),
            ChartText::value(0.0),
        ]);
    }

    public function testTheLegendIsTheChartsOwn(): void
    {
        $chart = self::read("title Sales\nh-axis [Q1, Q2]\nbar \"North\" [1, 2]\nline \"Goal\" [2, 3]\nlegend right");
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_filter(Work::run($chart->drawing())->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));

        $this->assertSame('right', $chart->legend);
        foreach (['Sales', 'Q1', 'Q2', 'North', 'Goal', '0', '100'] as $text) {
            $this->assertContains($text, $texts);
        }
    }

    public function testAChartOnItsSideTakesItsCategoriesDown(): void
    {
        $chart = self::read("orientation horizontal\nv-axis [Alpha, Beta]\nh-axis \"Score\" 0 --> 50\nbar [12, 45] labels");
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_filter(Work::run($chart->drawing())->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));

        $this->assertTrue($chart->horizontal);
        $this->assertSame(['Alpha', 'Beta'], $chart->yLabels);
        foreach (['Alpha', 'Beta', 'Score', '0', '50', '12', '45'] as $text) {
            $this->assertContains($text, $texts);
        }
    }

    public function testTheModelHasANodeForEachSeries(): void
    {
        $graph = self::read("title Sales\nh-axis [a]\nbar \"North\" [1]\narea [2]")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['n0', 'North'], ['n1', 'area1']], \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes));
        $this->assertSame('Sales', $graph->title);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'a value that is not a number' => ["h-axis [a]\nbar [1, x]", 'Invalid number format in bar data'];
        yield 'a pair it cannot read' => ["h-axis 0 --> 5\nline [(1, 2, 3)]", 'Invalid coordinate pair format in line data'];
        yield 'pairs across categories' => ["h-axis [a, b]\nline [(1, 2)]", 'not categorical labels'];
        yield 'pairs without a range' => ['scatter [(1, 2)]', 'requires explicit h-axis range'];
        yield 'pairs outside the range' => ["h-axis 0 --> 5\nline [(6, 2)]", 'X-coordinate 6.0 is outside h-axis range [0.0, 5.0]'];
        yield 'pairs and lists mixed' => ["h-axis 0 --> 5\nline [(1, 2)]\nline [1, 2]", 'All series must use the same data format'];
        yield 'an annotation up nowhere' => ['annotation "A" at (a, b)', 'Y position must be a numeric value: b'];
        yield 'ticks without labels' => ['v-axis ticks [0:Low]', 'Invalid tick format'];
        yield 'no spacing' => ['h-axis [a] spacing 0', 'X-axis spacing must be greater than 0'];
        yield 'no colour' => ["h-axis [a]\nbar [1] #nocolour", 'No such color'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startchart\n".$lines."\n@endchart"));
    }

    private static function read(string $lines): PlantUmlChart
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startchart\n".$lines."\n@endchart");
        self::assertInstanceOf(PlantUmlChart::class, $diagram);

        return $diagram;
    }
}
