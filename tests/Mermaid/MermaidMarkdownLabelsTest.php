<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidPainter;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Text\FallbackFonts;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

/** Markdown labels size their elements by the wrapped text and draw their styled runs. */
final class MermaidMarkdownLabelsTest extends TestCase
{
    public function testNodesEdgesAndFramesKeepTheirRunsThroughLayoutAndTitles(): void
    {
        $scene = NativeFixtureScene::render("---\ntitle: Runs\n---\nflowchart LR\nsubgraph S[\"`**Team**`\"]\nA[\"`**Bold** and *it*`\"]\nend\nA -->|\"`_edge_`\"| B[plain]\n");
        $node = self::named($scene, 'A');
        $this->assertSame('Bold and it', $node->label);
        $this->assertSame(
            [[
                ['Bold',  FontFace::Bold],
                [' and ', FontFace::Regular],
                ['it',    FontFace::Italic],
            ]],
            $scene->runs['node:'.$node->id],
        );
        $this->assertSame([[['edge', FontFace::Italic]]], $scene->runs['edge:0']);
        $this->assertSame([[['Team', FontFace::Bold]]], $scene->runs['cluster:0']);
        $this->assertArrayNotHasKey('node:'.self::named($scene, 'B')->id, $scene->runs);
        $marks = self::marks($scene);
        $bold = self::mark($marks, 'Bold');
        $italic = self::mark($marks, 'it');
        $and = self::mark($marks, 'and');
        $this->assertSame(FontFace::Bold, $bold->font);
        $this->assertSame(FontFace::Italic, $italic->font);
        $this->assertSame(FontFace::Regular, $and->font);
        // Runs sit side by side, one advance per character, centered on the node together.
        $this->assertEqualsWithDelta($bold->points[0][0] + 2 + 1 + 1.5, $and->points[0][0], 1e-9);
        $this->assertEqualsWithDelta($node->x, ($bold->points[0][0] - 2 + $italic->points[0][0] + 1) / 2, 1e-9);
        $this->assertSame(FontFace::Italic, self::mark($marks, 'edge')->font);
        $this->assertSame(FontFace::Bold, self::mark($marks, 'Team')->font);
    }

    public function testAutoWrapFollowsTheFamilyWidthUnlessDisabled(): void
    {
        $long = 'a very long markdown string that should wrap automatically';
        $wrapped = self::named(NativeFixtureScene::render("flowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame("a very long\nmarkdown string\nthat should wrap\nautomatically", $wrapped->label);
        $narrow = self::named(NativeFixtureScene::render("---\nconfig: {flowchart: {wrappingWidth: 60}}\n---\nflowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame("a very\nlong\nmarkdown\nstring\nthat\nshould\nwrap\nautomatically", $narrow->label);
        $flat = self::named(NativeFixtureScene::render("---\nconfig: {markdownAutoWrap: false}\n---\nflowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame($long, $flat->label);
        $mindmap = NativeFixtureScene::render("---\nconfig: {mindmap: {maxNodeWidth: 75}}\n---\nmindmap\nroot[\"`**Start** of a long idea`\"]");
        $root = \array_find($mindmap->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->label, 'Start'));
        $this->assertSame("Start of a\nlong idea", $root?->label);
        $this->assertNotEmpty($mindmap->runs);
    }

    public function testPngTextFallsBackPerCharacterAndBoxesWhatGdCannotDraw(): void
    {
        if (!\function_exists('imagettftext')) {
            $this->markTestSkipped('GD with FreeType is required');
        }
        $drawing = new Drawing(40, 10, [new Mark(MarkKind::Text, [[20.0, 5.0]], 'A & 漢 😎')]);
        $work = new PngBackend(fallbacks: new FallbackFonts(['/nonexistent/font.ttf']))->render($drawing);
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
    }

    private static function named(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node);

        return $node;
    }

    /** @return list<Mark> */
    private static function marks(MermaidScene $scene): array
    {
        $work = MermaidPainter::scene($scene);
        \iterator_to_array($work);

        return $work->getReturn()->marks;
    }

    /** @param list<Mark> $marks */
    private static function mark(array $marks, string $text): Mark
    {
        $mark = \array_find($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && $text === $mark->text);
        self::assertInstanceOf(Mark::class, $mark, 'No text mark '.$text);

        return $mark;
    }
}
