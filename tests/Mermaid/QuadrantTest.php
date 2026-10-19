<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidPainter;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\QuadrantDiagram;
use Janalis\Viz\Mermaid\QuadrantLayout;
use Janalis\Viz\Mermaid\QuadrantLexer;
use Janalis\Viz\Mermaid\QuadrantParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuadrantTest extends TestCase
{
    private static function parse(string $source): QuadrantDiagram
    {
        $parser = new QuadrantParser();
        foreach (new QuadrantLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new QuadrantLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testMetadataAxesUnicodeQuotedArrowsAndStatementSourceLocations(): void
    {
        $source = "%% intro\nQUADRANTCHART; x-Axis \"低い --> literal\" ---> \"高い\"; y-axis Bottom -->\n title Raw # ; %% literal unmatched [\naccTitle: accessible # ; %% literal\naccDescr {\nmultiline ; %% literal\n}\nquadrant-1 \"Top #amp; right\"\n  \"Point ; %% : 中文\": [0.25, 0.75]\n  Same: [0, 1]\n  Same: [1, 0]";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('低い --> literal', $diagram->labels['x-axis-low']['label']);
        $this->assertSame('高い', $diagram->labels['x-axis-high']['label']);
        $this->assertSame('Bottom ⟶', $diagram->labels['y-axis-low']['label']);
        $this->assertArrayNotHasKey('y-axis-high', $diagram->labels);
        $this->assertSame('Raw # ; %% literal unmatched [', $diagram->labels['title']['label']);
        $this->assertSame('accessible # ; %% literal', $diagram->labels['acctitle']['label']);
        $this->assertSame('multiline ; %% literal', $diagram->labels['accdescr']['label']);
        $this->assertSame('Top & right', $diagram->labels['quadrant-1']['label']);
        $this->assertSame(['Point ; %% : 中文', 'Same', 'Same'], \array_column($diagram->points, 'label'));
        $this->assertSame([8, 9, 10], \array_column($diagram->points, 'origin'));
        $tokens = \iterator_to_array(new QuadrantLexer($source)->statements());
        $point = \array_find($tokens, static fn ($statement): bool => \str_starts_with($statement->text, '"Point'));
        $this->assertSame(\strpos($source, needle: '"Point'), $point->offset);
        $this->assertSame(8, $point->line);
        $this->assertSame(2, $point->column);
        $this->assertSame('Only', self::parse("quadrantChart\ny-axis Only")->labels['y-axis-low']['label']);
        $this->assertSame('end', self::parse("quadrantChart\naccDescr: end")->labels['accdescr']['label']);
    }

    public function testClassDefinitionKeywordsAreCaseInsensitive(): void
    {
        $diagram = self::parse("quadrantChart\nA:::later: [0, 1]\nCLASSDEF later radius: 10\ncLaSsDeF later radius: 20, color: #f00");
        $this->assertSame(['radius' => 20.0, 'color' => '#ff0000'], $diagram->classes['later']);
        $this->assertSame('later', $diagram->points[0]['class']);
    }

    public function testSingleEndedAxisLabelsDecodeEntitiesOnlyOnce(): void
    {
        $diagram = self::parse("quadrantChart\nx-axis \"&amp;gt;\" -->");
        $this->assertSame('&gt; ⟶', $diagram->labels['x-axis-low']['label']);
        $this->assertArrayNotHasKey('x-axis-high', $diagram->labels);
    }

    public function testClassesResolveForwardReferencesAndInlineStylesOverrideOnlyTheirOwnProperties(): void
    {
        $source = "quadrantChart\nA:::later: [0.25, 0.75] radius: 20, color: #f00\nB:::later: [0.75, 0.25]\nC:::missing: [0.5, 0.5]\nclassDef later radius: 10, color: #123456, stroke-color: #ABC, stroke-width: 3px\nclassDef later radius: 15, color: #123456, stroke-color: #ABC, stroke-width: 3px";
        $diagram = self::parse($source);
        $this->assertSame('later', $diagram->points[0]['class']);
        $this->assertSame(['radius' => 20.0, 'color' => '#ff0000'], $diagram->points[0]['style']);
        $scene = self::scene($source);
        [$a, $b, $c] = \array_values($scene->graph->nodes);
        $this->assertSame(4.0, $a->w);
        $this->assertSame(3.0, $b->w);
        $this->assertSame(1.0, $c->w);
        $this->assertSame('#ff0000', $a->fillColor);
        $this->assertSame('#123456', $b->fillColor);
        $this->assertSame('#aabbcc', $a->color);
        $this->assertSame(0.3, $a->strokeWidth);
        $this->assertSame(0.0, $c->strokeWidth);
        $this->assertSame('@quadrantPointFill|textColor', $c->fillColor);
        $this->assertSame('@quadrantPointFill|textColor', $c->color);
        $drawing = MermaidPainter::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $point = \array_find_key($marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#ff0000' === $mark->fill);
        $this->assertIsInt($point);
        $this->assertSame(0.3, $marks[$point]->strokeWidth);
        $this->assertSame('text', $marks[$point + 1]->kind->value);
        $this->assertGreaterThan($a->y + ($a->h / 2), $marks[$point + 1]->points[0][1]);
    }

    public function testLongLabelsAndLargeStylesKeepEveryNodeWithinTheCanvasAndYield(): void
    {
        $scene = self::scene(
            "quadrantChart\ntitle "
            .\str_repeat('long ', times: 200)
            ."\nx-axis "
            .\str_repeat('axis ', times: 100)
            ."\ny-axis Bottom --> Top\nquadrant-1 "
            .\str_repeat('quadrant ', times: 100)
            ."\nA: [0, 1] radius: 1000, stroke-width: 1000px\nB: [1, 0] radius: 0, stroke-width: 0px",
        );
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $empty = self::scene('quadrantChart');
        $this->assertSame([], $empty->elements);
        $this->assertSame([], $empty->graph->nodes);
        $this->assertCount(5, $empty->graph->clusters);
    }

    public function testPointStrokeWidthReachesTheRasterBackend(): void
    {
        $scene = self::scene("quadrantChart\nA: [0.5, 0.5] radius: 10, color: #f00, stroke-color: #0f0, stroke-width: 10px");
        $drawing = MermaidPainter::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $work = new PngBackend()->render($drawing->getReturn());
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $point = \array_first($scene->graph->nodes);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(0x00_ff00, \imagecolorat($image, (int) ($point->x * 10) + 13, (int) ($point->y * 10)));
        $this->assertSame(0xff_0000, \imagecolorat($image, (int) ($point->x * 10), (int) ($point->y * 10)));
    }

    public function testEmptyChartsCenterQuadrantCaptionsAndPlaceTheXAxisAboveThePlot(): void
    {
        $scene = self::scene("quadrantChart\nx-axis Low --> High\nquadrant-1 Caption");
        $nodes = \array_column($scene->graph->nodes, column_key: null, index_key: 'name');
        $this->assertSame($scene->graph->clusters[0]->y0 + 25.0, $nodes['quadrant-1']->y);
        $this->assertLessThan($scene->graph->clusters[0]->y0, $nodes['x-axis-low']->y);
        $this->assertCount(0, $scene->selectionGraph->nodes);
    }

    public function testMultilineQuotedLabelsKeepTheFollowingStatementsLocations(): void
    {
        $source = "quadrantChart\n  \"First\nsecond\": [0.25, 0.75]; Other: [0.75, 0.25]";
        $statements = \iterator_to_array(new QuadrantLexer($source)->statements());
        $this->assertCount(3, $statements);
        $this->assertSame(2, $statements[2]->line);
        $this->assertSame(\strpos($source, needle: 'Other') - \strrpos($source, needle: "\n") - 1, $statements[2]->column);
        $this->assertSame(\strpos($source, needle: 'Other'), $statements[2]->offset);
        $this->assertSame(["First\nsecond", 'Other'], \array_column(self::parse($source)->points, 'label'));
    }

    #[DataProvider('invalidSources')]
    public function testMalformedSyntaxAndBounds(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected quadrantChart'];
        yield ['wrong', 'Expected quadrantChart'];
        yield ["quadrantChart\nx-axis --> High", 'axis requires a low label'];
        yield ["quadrantChart\nP: [", 'Invalid quadrant statement'];
        yield ["quadrantChart\nnot a point", 'Expected quadrant point'];
        yield ["quadrantChart\nP: [2, 0]", 'between zero and one'];
        yield ["quadrantChart\nP: [0, -1]", 'between zero and one'];
        yield ["quadrantChart\nP: [0, 2]", 'between zero and one'];
        yield ["quadrantChart\nP: [-1, 0]", 'between zero and one'];
        yield ["quadrantChart\nP: [".\str_repeat('9', times: 400).', 0]', 'finite'];
        yield ["quadrantChart\nP: [0, ".\str_repeat('9', times: 400).']', 'finite'];
        yield ["quadrantChart\nP: [0, 0] unsupported: yes", 'unsupported quadrant point style'];
        yield ["quadrantChart\nP: [0, 0] color: red", 'hex color'];
        yield ["quadrantChart\nP: [0, 0] stroke-color: #12345", 'hex color'];
        yield ["quadrantChart\nP: [0, 0] radius: -1", 'requires an integer'];
        yield ["quadrantChart\nP: [0, 0] stroke-width: 3", 'integer pixels'];
        yield ["quadrantChart\nP: [0, 0] radius: 1001", '1000 pixel limit'];
        yield ["quadrantChart\nP: [0, 0] stroke-width: 1001px", '1000 pixel limit'];
        yield ["quadrantChart\n".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["quadrantChart\ntitle Literal %%".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["quadrantChart\n".\str_repeat("P: [0, 0]\n", times: 4097), 'quadrant point limit'];
        $classes = 'quadrantChart';
        for ($index = 0; $index < 4097; ++$index) {
            $classes .= "\nclassDef c".$index.' radius: 1';
        }
        yield [$classes, 'quadrant class limit'];
    }

    public function testCaptionKindsTakeTheirPinnedFontSizes(): void
    {
        $body = "quadrantChart\ntitle T\nx-axis Low --> High\ny-axis Down --> Up\nquadrant-1 Q\nA: [0.5, 0.5]";
        $scales = static fn (MermaidScene $scene): array => \array_column(\array_map(static fn (GraphNode $node): array => [$node->name, $node->fontScale], $scene->graph->nodes), 1, 0);
        $plain = $scales(NativeFixtureScene::render($body));
        $this->assertSame([1.0], \array_values(\array_unique($plain)));
        $sized = NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: 40\n    quadrantLabelFontSize: 8\n    xAxisLabelFontSize: 24\n    yAxisLabelFontSize: 32\n    pointLabelFontSize: 18\n---\n"
        .$body);
        $scaled = $scales($sized);
        $this->assertSame([2.0, 0.5, 1.5, 1.5, 2.0, 2.0], [$scaled['title'], $scaled['quadrant-1'], $scaled['x-axis-low'], $scaled['x-axis-high'], $scaled['y-axis-low'], $scaled['y-axis-high']]);
        $point = \array_find($sized->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'point-'));
        $this->assertSame(1.5, $point?->fontScale);
        $title = \array_find($sized->graph->nodes, static fn (GraphNode $node): bool => 'title' === $node->name);
        $this->assertSame(8.0, $title?->h, 'the scaled caption sizes its box');
        $zero = $scales(NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: 0\n---\n".$body));
        $this->assertSame(1.0, $zero['title']);
        $text = $scales(NativeFixtureScene::render("---\nconfig:\n  quadrantChart:\n    titleFontSize: '40'\n---\n".$body));
        $this->assertSame(2.0, $text['title'], 'numeric text reads as its number, as pinned arithmetic does');
    }
}
