<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphFont;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\WardleyDiagram;
use Janalis\Viz\Mermaid\WardleyLayout;
use Janalis\Viz\Mermaid\WardleyLexer;
use Janalis\Viz\Mermaid\WardleyParser;
use Janalis\Viz\Mermaid\WardleySymbols;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WardleyTest extends TestCase
{
    private static function parse(string $source): WardleyDiagram
    {
        $parser = new WardleyParser();
        foreach (new WardleyLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPipelineComponentsInheritFinalParentCoordinatesAndResolveByLabel(): void
    {
        $diagram = self::parse(<<<'MMD'
            wardley-beta
            pipeline Parent {
                component Early [0.2] label [-20, 10]
                component Late [0.8]
            }
            component Parent [0.6, 0.4]
            component User [0.9, 0.5]
            User -> Late
            Early -> Parent
            Missing -> Unknown
            MMD);
        $this->assertSame(['Parent_Early', 'Parent_Late'], $diagram->pipelines['Parent']);
        $this->assertSame(0.6, $diagram->nodes['Parent_Early']['visibility']);
        $this->assertSame(-2.0, $diagram->nodes['Parent_Early']['dx']);
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(1, $scene->graph->edgeCount(), 'unknown and child-to-parent links are not painted upstream');
        $parent = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent' === $node->name);
        $early = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent_Early' === $node->name);
        $late = \array_find($scene->graph->nodes, static fn ($node): bool => 'Parent_Late' === $node->name);
        $this->assertSame($early->y, $late->y);
        $this->assertSame(($early->x + $late->x) / 2, $parent->x);
        $this->assertLessThan($early->y, $parent->y);
        $this->assertSame('rect', $scene->shapes[$parent->id]);
        $this->assertCount(2, $scene->graph->clusters);
        $anchorPipeline = "wardley-beta\nanchor Anchor [0.5,0.5]\npipeline Anchor {\ncomponent Only [0.4]\n}";
        $this->assertStringContainsString('Anchor', NativeFixtureExport::render($anchorPipeline, Format::Svg));
    }

    public function testFlowsStylesAndStageBoundariesHaveDistinctNativeGeometry(): void
    {
        $source = <<<'MMD'
            wardley-beta
            evolution "Idea -> concept"@0.2 / Concept -> Build@0.4 -> Product@0.75 -> Utility@1.0
            component A [0.8, 0.1] (outsource)
            component B [0.6, 0.4] (buy)
            component C [0.4, 0.7] (market)
            component D [0.2, 0.9] (inertia)
            A +<> B
            B +< C; reverse
            C -.-> D +>
            D +'return'< A
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('Idea -> concept / Concept', $diagram->stages[0]['label']);
        $this->assertSame([0.2, 0.4, 0.75, 1.0], \array_column($diagram->stages, 'boundary'));
        $this->assertSame('; reverse', $diagram->links[1]['label']);
        $this->assertSame(['both', 'backward', 'forward', 'backward'], \array_column($diagram->links, 'flow'));
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame(4, $scene->graph->edgeCount());
        $frame = $scene->graph->clusters[0];
        $divider = \array_find($scene->graph->edges, static fn (GraphEdge $edge): bool => '#000000cc' === $edge->color);
        $this->assertSame($frame->x0 + (0.2 * ($frame->x1 - $frame->x0)), $divider->points[0][0]);
        $reverse = $scene->graph->relationships[1];
        $tip = $reverse->arrowTip;
        $this->assertNotNull($tip);
        $tail = $scene->graph->nodes[$reverse->tail];
        $head = $scene->graph->nodes[$reverse->head];
        $this->assertLessThan(\hypot($tip[0] - $head->x, $tip[1] - $head->y), \hypot($tip[0] - $tail->x, $tip[1] - $tail->y));
        $this->assertCount(1, \array_filter($scene->graph->relationships, static fn (GraphEdge $edge): bool => $edge->dashed));
        $this->assertCount(5, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->arrowTip));
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $this->assertGreaterThan(30, \count($drawing->getReturn()->marks));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('#666666', $svg);
        $this->assertStringContainsString('#cccccc', $svg);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['wardley'];
        yield 'coordinates' => ["wardley-beta\ncomponent A [0.4]"];
        yield 'numeric range' => ["wardley-beta\ncomponent A [1000.0, 0.5]"];
        yield 'canvas' => ["wardley-beta\nsize [10, 200]"];
        yield 'large canvas' => ["wardley-beta\nsize [100000, 200]"];
        yield 'decorator' => ["wardley-beta\ncomponent A [0.4,0.5] (invalid)"];
        yield 'anchor decorator' => ["wardley-beta\nanchor A [0.4,0.5] (buy)"];
        yield 'offset limit' => ["wardley-beta\ncomponent A [0.4,0.5] label [1000000,0]"];
        yield 'empty pipeline' => ["wardley-beta\npipeline A {\n}"];
        yield 'unclosed pipeline' => ["wardley-beta\npipeline A {\ncomponent B [0.5]"];
        yield 'unknown pipeline parent' => ["wardley-beta\npipeline A {\ncomponent B [0.5]\n}"];
        yield 'pipeline decorator' => ["wardley-beta\npipeline A {\ncomponent B [0.5] (buy)\n}"];
        yield 'pipeline content' => ["wardley-beta\npipeline A {\nA -> B\n}"];
        yield 'single stage' => ["wardley-beta\nevolution Only"];
        yield 'stage punctuation' => ["wardley-beta\nevolution First -> @bad"];
        yield 'bad annotation' => ["wardley-beta\nannotation invalid"];
        yield 'named box' => ["wardley-beta\nannotations Wrong [0,1]"];
        yield 'unquoted note' => ["wardley-beta\nnote Wrong [0.4,0.5]"];
        yield 'nameless accelerator' => ["wardley-beta\naccelerator [0.4,0.5]"];
        yield 'missing link operator' => ["wardley-beta\nFirst Second"];
        yield 'invalid link' => ["wardley-beta\nA --- B"];
        yield 'unclosed quote' => ["wardley-beta\ncomponent \"Missing [0.4,0.5]"];
    }

    #[DataProvider('invalid')]
    public function testInvalidDeclarationsFailWithLocatedErrors(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testEmptyInputInvalidCoordinatesAndBoundedDeclarations(): void
    {
        foreach (['', 'wrong'] as $source) {
            try {
                self::parse($source);
                $this->fail('Invalid header accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame(0, $error->offset);
            }
        }
        try {
            WardleyParser::coordinate('nonnumeric', new MermaidStatement('', offset: 4, line: 2, column: 1));
            $this->fail('Nonnumeric coordinate accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([4, 2, 1], [$error->offset, $error->sourceLine, $error->column]);
        }
        $parser = new WardleyParser();
        $parser->accept(new MermaidStatement('wardley-beta', offset: 0, line: 0, column: 0));
        for ($i = 0; $i < 4096; ++$i) {
            $parser->accept(new MermaidStatement('component A [0.5,0.5]', offset: 13, line: 1, column: 0));
        }
        $this->assertCount(1, $parser->finish()->nodes);
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('component B [0.5,0.5]', offset: 13, line: 1, column: 0));
    }

    public function testCoordinatesNamesStrategiesTrendsAndMetadataPreserveSourceSemantics(): void
    {
        $source = <<<'MMD'
            %% overview
            wardley-beta
            title Value chain
            accTitle: Chain
            accDescr {Visibility and evolution}
            anchor "User %% literal" [0.95, 0.5]
            component Service [75.0, 25.0] label [-30, 20] (build) inertia
            component "Cloud" [0.2, 0.9] (market)
            "User %% literal" -> Service
            Service +'use'> Cloud
            evolve Service 0.6
            evolve Service 0.7
            evolve Absent 0.9
            MMD;
        $diagram = self::parse($source);
        $this->assertSame([0.75, 0.25], [$diagram->nodes['Service']['visibility'], $diagram->nodes['Service']['evolution']]);
        $this->assertSame([-3.0, 2.0, 'build', true], [
            $diagram->nodes['Service']['dx'],
            $diagram->nodes['Service']['dy'],
            $diagram->nodes['Service']['strategy'],
            $diagram->nodes['Service']['inertia'],
        ]);
        $this->assertSame('User %% literal', $diagram->nodes['User %% literal']['label']);
        $this->assertSame(['forward', 'use'], [$diagram->links[1]['flow'], $diagram->links[1]['label']]);
        $this->assertSame(0.7, $diagram->trends['Service']['target']);
        $this->assertSame(6, $diagram->nodes['Service']['origin']);
        $this->assertCount(3, $diagram->metadata);
        $work = new WardleyLayout()->layout($diagram);
        $this->assertGreaterThan(50, \count(\iterator_to_array($work)));
        $scene = $work->getReturn();
        $service = \array_find($scene->graph->nodes, static fn ($node): bool => 'Service' === $node->name);
        $cloud = \array_find($scene->graph->nodes, static fn ($node): bool => 'Cloud' === $node->name);
        $user = \array_find($scene->graph->nodes, static fn ($node): bool => 'User %% literal' === $node->name);
        $this->assertLessThan($cloud->x, $service->x);
        $this->assertLessThan($cloud->y, $service->y);
        $this->assertSame(GraphFont::Bold, $user->font);
        $marketMarks = WardleySymbols::marks($cloud, $scene->shapes[$cloud->id]);
        $this->assertCount(3, \array_filter($marketMarks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind));
        $this->assertCount(1, \array_filter($marketMarks, static fn (MermaidMark $mark): bool => 'polyline' === $mark->kind));
        $this->assertSame(6, $scene->elements[$service->id]);
        $caption = \array_find($scene->graph->nodes, static fn ($node): bool => '@label:Service' === $node->name);
        $this->assertSame([$service->x - 3, $service->y + 2], [$caption->x - ($caption->w / 2), $caption->y]);
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $inertiaX = $service->x + ($service->w / 2) + 0.8;
        $this->assertContains([[$inertiaX, $service->y - 1.5], [$inertiaX, $service->y + 1.5]], \array_column($drawing->getReturn()->marks, 'points'));
        $this->assertSame(2, $scene->graph->edgeCount());
        $this->assertStringContainsString('inertia', $scene->shapes[$service->id]);
        // The one trend on a known component is a dashed evolution arrow from it; the absent one is skipped.
        $this->assertContains('evolve Service → 0.7', \array_column($scene->legend, 'text'));
        $this->assertNotContains('evolve Absent → 0.9', \array_column($scene->legend, 'text'));
        $trends = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->tail === $service->id && $edge->head === $service->id && $edge->dashed));
        $this->assertCount(1, $trends);
        $this->assertSame([$service->x, $service->y], $trends[0]->points[0]);
        $this->assertSame($trends[0]->points[1], $trends[0]->arrowTip);
        $this->assertGreaterThan($service->x, $trends[0]->points[1][0]);
        $this->assertSame($service->y, $trends[0]->points[1][1]);
        // The title caption joins the picture; accTitle and accDescr do not.
        $this->assertContains('Value chain', \array_column($scene->graph->nodes, 'label'));
        $this->assertNotContains('Chain', \array_column($scene->graph->nodes, 'label'));
        $this->assertStringContainsString('Value chain', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }

    public function testAnnotationsNotesArrowsAndCustomCanvasSurviveExports(): void
    {
        $source = <<<'MMD'
            wardley-beta
            size [1500, 800]
            component Point [0.5, 0.5] label [-1200, -800]
            note "Critical point" [0.4, 0.5]
            annotations [1, 0]
            annotations [0, 1]
            annotation 2,[0.4, 0.6] "Second"
            annotation 1,[0.6, 0.4] "First"
            accelerator Faster [0.3, 0.3]
            deaccelerator Slower [0.7, 0.7]
            evolve Absent 0.9
            MMD;
        $diagram = self::parse($source);
        $this->assertSame([150.0, 80.0], [$diagram->width, $diagram->height]);
        $this->assertCount(7, $diagram->annotations);
        $this->assertSame(['note', 'annotations', 'annotations', 'annotation', 'annotation', 'accelerator', 'deaccelerator'], \array_column($diagram->annotations, 'kind'));
        $this->assertSame(['2. Second', 0.4, 0.6, 6], [
            $diagram->annotations[3]['label'],
            $diagram->annotations[3]['visibility'],
            $diagram->annotations[3]['evolution'],
            $diagram->annotations[3]['origin'],
        ]);
        $this->assertSame(['Faster', 0.3, 0.3, 8], [
            $diagram->annotations[5]['label'],
            $diagram->annotations[5]['visibility'],
            $diagram->annotations[5]['evolution'],
            $diagram->annotations[5]['origin'],
        ]);
        $work = new WardleyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(6, $scene->elements);
        $named = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(['circle', 'circle', 'rect', 'wardley:accelerator:', 'wardley:deaccelerator:'], [
            $scene->shapes[$named['@annotation:3']->id],
            $scene->shapes[$named['@annotation:4']->id],
            $scene->shapes[$named['@note:0']->id],
            $scene->shapes[$named['@accelerator:5']->id],
            $scene->shapes[$named['@deaccelerator:6']->id],
        ]);
        $this->assertSame(['2', '1', 'Critical point'], [$named['@annotation:3']->label, $named['@annotation:4']->label, $named['@note:0']->label]);
        $this->assertSame(
            ['component: Point [0.5, 0.5]', 'note: Critical point', 'annotation: 2. Second', 'annotation: 1. First', 'accelerator: Faster', 'deaccelerator: Slower'],
            \array_column($scene->legend, 'text'),
        );
        foreach (['@accelerator:5', '@deaccelerator:6'] as $name) {
            $arrow = $named[$name];
            $direction = \str_starts_with($name, '@deaccelerator:') ? -1 : 1;
            $this->assertContains([$arrow->x + (($direction * $arrow->w) / 2), $arrow->y], MermaidOutline::points($arrow, $scene->shapes[$arrow->id]));
            $this->assertTrue(MermaidOutline::contains($arrow, $scene->shapes[$arrow->id], $arrow->x, $arrow->y));
            $this->assertFalse(MermaidOutline::contains($arrow, $scene->shapes[$arrow->id], $arrow->x, $arrow->y + $arrow->h));
            $marks = MermaidOutline::marks($arrow, $scene->shapes[$arrow->id]);
            $this->assertCount(1, $marks);
            $this->assertSame(['polygon', '#64748b', '#e2e8f0'], [$marks[0]->kind, $marks[0]->stroke, $marks[0]->fill]);
            $this->assertSame(MermaidOutline::points($arrow, $scene->shapes[$arrow->id]), $marks[0]->points);
        }
        $this->assertSame(0, $scene->graph->edgeCount());
        $box = $named['@annotations-box'];
        $this->assertSame("1. First\n2. Second", $box->label);
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $this->assertStringContainsString('Critical point', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }

    public function testAcceleratorArrowsPointRightAndDeceleratorsMirrorThem(): void
    {
        $node = new GraphNode(0, 'n', '', 10, 10, 8, 4, null, null, dashed: false);
        $right = [[6.0, 9.0], [12.0, 9.0], [12.0, 8.0], [14.0, 10.0], [12.0, 12.0], [12.0, 11.0], [6.0, 11.0]];
        $this->assertSame($right, WardleySymbols::arrow($node, 'accelerator'));
        $this->assertSame([[14.0, 9.0], [8.0, 9.0], [8.0, 8.0], [6.0, 10.0], [8.0, 12.0], [8.0, 11.0], [14.0, 11.0]], WardleySymbols::arrow($node, 'deaccelerator'));
    }

    public function testComponentPrecedenceAndPartialStageBoundariesMatchPinnedPopulation(): void
    {
        $source = "wardley-beta\nevolution First@0.2 -> Last\ncomponent Same [0.3, 0.4]\nanchor Other [0.8, 0.8]\nanchor Same [0.9, 0.9]\ncomponent Last [0.2, 0.8]\nSame --> Last";
        $diagram = self::parse($source);
        $this->assertSame(['Other', 'Same', 'Last'], \array_keys($diagram->nodes));
        $this->assertSame('component', $diagram->nodes['Same']['kind']);
        $this->assertSame(0.3, $diagram->nodes['Same']['visibility']);
        $this->assertSame(0.4, $diagram->nodes['Same']['evolution']);
        $this->assertStringContainsString('First', NativeFixtureExport::render($source, Format::Svg));
    }
}
