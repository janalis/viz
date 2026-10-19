<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphFont;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\ErCard;
use Janalis\Viz\Mermaid\MermaidCss;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidFont;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSvg;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidTypographyTest extends TestCase
{
    public function testFacesCascadeResetAndProduceDistinctRasterGlyphs(): void
    {
        $hashes = [];
        foreach (GraphFont::cases() as $face) {
            $style = ['font-weight' => 'bold' === $face->weight(), 'font-style' => 'italic' === $face->style()];
            $this->assertSame($face, GraphFont::Regular->overlay($style));
            $this->assertSame($face, $face->overlay([]));
            $this->assertSame(GraphFont::Regular, $face->overlay(['font-weight' => false, 'font-style' => false]));
            $this->assertFileExists(MermaidFont::file($face));
            $drawing = new MermaidDrawing(60, 12, [new MermaidMark('text', [[30.0, 7.0]], 'Hamburgefontsiv', font: $face)]);
            $png = new MermaidPng()->render($drawing);
            \iterator_to_array($png);
            $this->assertStringStartsWith("\x89PNG", $png->getReturn());
            $hashes[] = \hash('sha256', $png->getReturn());
            $svg = MermaidSvg::render($drawing);
            \iterator_to_array($svg);
            $this->assertStringContainsString('font-style:'.$face->style().';font-weight:'.$face->weight(), $svg->getReturn());
            if (GraphFont::Regular === $face) {
                continue;
            }
            $this->assertStringContainsString('font-style="'.$face->style().'" font-weight="'.$face->weight().'"', $svg->getReturn());
        }
        $this->assertCount(4, \array_unique($hashes));
    }

    public function testCompartmentDividersFollowScaledLabelRows(): void
    {
        $node = new GraphNode(0, 'A', "Header\n\nAttribute", 20.0, 10.0, 30.0, 16.0, color: null, fillColor: null, dashed: false, fontScale: 2.0);
        $marks = ErCard::dividers($node, 'er-card:1');
        $this->assertSame([[5.0, 10.0], [35.0, 10.0]], $marks[0]->points);
    }

    public function testRasterFontSizesChangeGlyphsOnAnIdenticalCanvas(): void
    {
        $hashes = [];
        foreach ([1.0, 2.0] as $scale) {
            $drawing = new MermaidDrawing(60, 16, [new MermaidMark('text', [[30.0, 9.0]], 'Scaled text', fontScale: $scale)]);
            $png = new MermaidPng()->render($drawing);
            \iterator_to_array($png);
            $hashes[] = \hash('sha256', $png->getReturn());
        }
        $this->assertNotSame($hashes[0], $hashes[1]);
    }

    public function testEdgeCaptionsAndClusterHeadersUseTheirAuthoredFaces(): void
    {
        $svg = NativeFixtureExport::render("graph LR\nA -->|Typed edge| B\nlinkStyle 0 font-style:italic,font-weight:bold", Format::Svg);
        $this->assertStringContainsString('font-style="italic" font-weight="bold">Typed edge</text>', $svg);
        $svg = NativeFixtureExport::render("graph LR\nsubgraph G[Styled group]\nA\nend\nstyle G font-style:italic,font-weight:bold", Format::Svg);
        $this->assertStringContainsString('font-style="italic" font-weight="bold">Styled group</text>', $svg);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'font size syntax' => ['font-size:large', 'Font size'];
        yield 'font size zero' => ['font-size:0', 'Font size'];
        yield 'font size limit' => ['font-size:1001px', 'Font size'];
        yield 'weight' => ['font-weight:heavy', 'font weight'];
        yield 'weight overflow' => ['font-weight:1000', 'font weight'];
        yield 'style' => ['font-style:slanted', 'font style'];
        yield 'border syntax' => ['border:solid red', 'border shorthand'];
        yield 'border width' => ['border:1001px solid red', 'stroke width'];
        yield 'border color' => ['border:1px solid invalid', 'style color'];
    }

    #[DataProvider('invalid')]
    public function testInvalidCssIsLocated(string $css, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        new MermaidCss('state')->parse($css, new MermaidStatement($css, offset: 10, line: 2, column: 3));
    }

    public function testNormalAndNumericFaceValuesOverrideAClassAndBordersCanBeRemoved(): void
    {
        $css = new MermaidCss('flowchart');
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $this->assertSame(['font-style' => false, 'font-weight' => false], $css->parse('font-style:normal,font-weight:normal', $statement));
        $this->assertSame(['font-weight' => false], $css->parse('font-weight:500', $statement));
        $this->assertSame(['font-weight' => true], $css->parse('font-weight:600', $statement));
        $this->assertSame(['stroke' => 'none'], $css->parse('border:NONE', $statement));
        $scene = NativeFixtureScene::render(
            "graph LR\nA\nB\nC\nclassDef em font-style:italic,font-weight:bold\nclass A,B,C em\nstyle A font-style:normal,font-weight:400\nstyle B font-weight:normal\nstyle C font-weight:700,border:none",
        );
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame([GraphFont::Regular, GraphFont::Italic, GraphFont::BoldItalic], [$nodes['A']->font, $nodes['B']->font, $nodes['C']->font]);
        $this->assertSame('none', $nodes['C']->color);
        $this->assertNull($nodes['A']->color);
    }
}
