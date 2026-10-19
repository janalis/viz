<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\FlowchartEdgeStyles;
use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\RankedGraphLayout;

final class FlowchartEdgeStylesTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new RankedGraphLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testIndexedStylesOverrideDefaultsAndRepeatedStylesReplaceProperties(): void
    {
        $source = "flowchart LR\nA & B -->|Work| C\nC -.-> A\nlinkStyle default stroke:blue,stroke-width:2px,color:purple\nlinkStyle 0,1 stroke:red,stroke-dasharray:5 5\nlinkStyle 1 color:green\nlinkStyle 2 stroke-dasharray:0";
        $scene = self::scene($source);
        $edges = $scene->graph->edges;
        $this->assertCount(3, $edges);
        $this->assertSame(['#ff0000', '#0000ff', '#0000ff'], \array_column($edges, 'color'));
        $this->assertSame(['#800080', '#008000', '#800080'], \array_column($edges, 'textColor'));
        $this->assertSame([true, false, false], \array_column($edges, 'dashed'));
        $this->assertSame([0.2, 0.2, 0.2], \array_column($edges, 'strokeWidth'));
        $this->assertSame('Work', $edges[0]->label);
        $plain = self::scene("flowchart LR\nA & B -->|Work| C\nC -.-> A");
        foreach ($edges as $index => $edge) {
            $original = $plain->graph->edges[$index];
            $this->assertSame([$original->tail, $original->head, $original->points, $original->arrowTip, $original->labelPos, $original->minX, $original->maxY, $original->ribbon], [
                $edge->tail,
                $edge->head,
                $edge->points,
                $edge->arrowTip,
                $edge->labelPos,
                $edge->minX,
                $edge->maxY,
                $edge->ribbon,
            ]);
        }
        $this->assertSame($plain->origins, $scene->origins);
        $this->assertSame($plain->elements, $scene->elements);
    }

    public function testDefaultsCanPrecedeEdgesAndDoNotAffectNodesOrUnstyledDiagrams(): void
    {
        $scene = self::scene('flowchart LR; linkStyle default stroke:red; A --> B; linkStyle default color:green;');
        $edge = $scene->graph->edges[0];
        $this->assertNull($edge->color);
        $this->assertSame('#008000', $edge->textColor);
        $this->assertSame(0.12, $edge->strokeWidth);
        $this->assertNull($scene->graph->nodes[0]->color);
        $plain = self::scene('flowchart LR; A --> B');
        $this->assertNull($plain->graph->edges[0]->textColor);
        $this->assertSame(0.12, $plain->graph->edges[0]->strokeWidth);
        $this->assertSame('#ff0000', self::scene('flowchart LR; A --> B; linkStyle 00 stroke:red')->graph->edges[0]->color);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'forward index' => ['linkStyle 1 stroke:red', 'out of bounds'];
        yield 'negative' => ['linkStyle -1 stroke:red', 'Expected flowchart linkStyle'];
        yield 'overflow' => ['linkStyle 99999999999999999999999 stroke:red', 'out of bounds'];
        yield 'fill' => ['linkStyle 0 fill:red', 'fill requires the Mermaid CLI'];
        yield 'unknown property' => ['linkStyle 0 filter:blur(2px)', 'Unsupported flowchart edge CSS'];
        yield 'color' => ['linkStyle 0 stroke:bogus', 'Invalid flowchart edge style color'];
    }

    #[DataProvider('invalid')]
    public function testInvalidStylesAreRejected(string $style, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::scene("flowchart LR\nA-->B\n".$style);
    }

    public function testStatementBudget(): void
    {
        $styles = new FlowchartEdgeStyles();
        $statement = new MermaidStatement('linkStyle default stroke:red', offset: 0, line: 0, column: 0);
        for ($index = 0; $index < 4096; ++$index) {
            $styles->accept($statement, edges: 0);
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('edge style limit');
        $styles->accept($statement, edges: 0);
    }

    public function testAssignmentBudget(): void
    {
        $styles = new FlowchartEdgeStyles();
        $statement = new MermaidStatement('linkStyle '.\str_repeat('0,', times: 1023).'0 stroke:red', offset: 0, line: 0, column: 0);
        for ($index = 0; $index < 64; ++$index) {
            $styles->accept($statement, edges: 1);
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('edge style-assignment limit');
        $styles->accept($statement, edges: 1);
    }

    public function testNamedClassesAndDirectEdgeStylesPrecedeIndexedOverrides(): void
    {
        $scene = self::scene("flowchart LR\nA e@-->|Work| B\nclassDef named stroke:red,color:green,font-size:32px\nclass e named\nstyle e stroke:purple\nlinkStyle default stroke:blue\n");
        $edge = $scene->graph->edges[0];
        $this->assertSame('#800080', $edge->color);
        $this->assertSame('#008000', $edge->textColor);
        $this->assertSame(2.0, $edge->fontScale);
        $override = self::scene("flowchart LR\nA e@-->|Work| B\nclassDef named stroke:red\nclass e named\nlinkStyle 0 stroke:blue\n");
        $this->assertSame('#0000ff', $override->graph->edges[0]->color);
    }
}
