<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use PHPUnit\Framework\TestCase;

final class FlowchartClusterStylesTest extends TestCase
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

    public function testNestedGroupStylesPreserveGeometryAndIgnoreTheImplicitNodeDefault(): void
    {
        $body = "flowchart TD\nsubgraph Outer[Services]\nsubgraph Inner[Workers]\nA --> B\nend\nC\nend\nsubgraph Plain\nD\nend";
        $plain = self::scene($body);
        $scene = self::scene(
            $body
            .'; classDef default fill:yellow,stroke:red; class Outer frame; Inner:::warning; classDef frame fill:#eef,stroke:blue,color:green; classDef warning fill:pink; style Inner stroke:none,stroke-width:4px,stroke-dasharray:5 5,color:purple',
        );
        $this->assertCount(4, $scene->graph->nodes);
        $this->assertCount(3, $scene->graph->clusters);
        $groups = \array_column($scene->graph->clusters, null, 'name');
        $this->assertSame('#eeeeff', $groups['Outer']->fillColor);
        $this->assertSame('#0000ff', $groups['Outer']->color);
        $this->assertSame('#008000', $groups['Outer']->textColor);
        $this->assertSame('#ffc0cb', $groups['Inner']->fillColor);
        $this->assertSame('none', $groups['Inner']->color);
        $this->assertSame('#800080', $groups['Inner']->textColor);
        $this->assertSame(0.4, $groups['Inner']->strokeWidth);
        $this->assertTrue($groups['Inner']->dashed);
        // Unstyled groups carry their color theme slot, which other themes resolve to their roles.
        $this->assertSame('@slot:fill:2', $groups['Plain']->fillColor);
        $this->assertSame('@slot:stroke:2', $groups['Plain']->color);
        $this->assertFalse($groups['Plain']->dashed);
        $original = \array_column($plain->graph->clusters, null, 'name');
        foreach ($groups as $name => $group) {
            $before = $original[$name];
            $this->assertSame([$before->x0, $before->y0, $before->x1, $before->y1, $before->label, $before->labelPos], [
                $group->x0,
                $group->y0,
                $group->x1,
                $group->y1,
                $group->label,
                $group->labelPos,
            ]);
        }
        $this->assertSame($plain->origins, $scene->origins);
        $this->assertSame($plain->elements, $scene->elements);
        $this->assertEquals($plain->graph->edges, $scene->graph->edges);
        $this->assertSame('#ffff00', $scene->graph->nodes[0]->fillColor);
    }

    public function testExplicitDefaultClassAndStylePrecedenceApplyToGroups(): void
    {
        $scene = self::scene('graph LR; subgraph G; A; end; classDef default fill:yellow,stroke:red; class G default; style G fill:white; style G color:blue; style G stroke-dasharray:0');
        $group = $scene->graph->clusters[0];
        $this->assertSame('#ffffff', $group->fillColor);
        $this->assertSame('#ff0000', $group->color);
        $this->assertSame('#0000ff', $group->textColor);
        $this->assertSame(0.12, $group->strokeWidth);
        $this->assertFalse($group->dashed);
    }
}
