<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\Activity;

use Janalis\Viz\Model\Activity\Action;
use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\Model\Activity\Note;
use Janalis\Viz\Model\Activity\Step;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Tests\Support\ActivitySample;
use PHPUnit\Framework\TestCase;

final class ActivityDiagramTest extends TestCase
{
    public function testTheGraphIsTheFlowchartOfTheActivity(): void
    {
        $diagram = ActivitySample::diagram();
        $graph = $diagram->graph();
        $label = static fn (string $text): ?Node => \array_find($graph->nodes, static fn (Node $node): bool => $text === $node->label);
        $links = \array_map(static fn (Edge $edge): string => $edge->from.'>'.$edge->to.('' === $edge->label ? '' : ':'.$edge->label), $graph->edges);

        $this->assertSame(Family::Activity, $diagram->family());
        $this->assertSame('Order', $graph->title);
        $this->assertSame([Shape::Circle, '#000000'], [$graph->nodes[0]->shape, $graph->nodes[0]->fill]);
        $this->assertSame(Shape::Rounded, $label("Read\norder")?->shape);
        $this->assertSame(Shape::Note, $label('checked')?->shape);
        $this->assertContains('n2>n1', $links, 'a left note points at its step');
        $paid = $label('paid?')?->id;
        $credit = $label('credit?')?->id;
        $this->assertContains($paid.'>'.$label('Ship')?->id.':yes', $links);
        $this->assertContains($paid.'>'.$credit, $links, 'an else-if is reached when the test before fails');
        $this->assertContains($credit.'>'.$label('Cancel')?->id.':no', $links);
        $gift = $label('gift?')?->id;
        $this->assertCount(2, \array_filter($links, static fn (string $link): bool => \str_starts_with($link, (string) $gift.'>')), 'with no else, the flow also skips past');
        $more = $label('more items?')?->id;
        $this->assertContains($label('Pack')?->id.'>'.$more, $links, 'a while loop returns to its test');
        $damaged = $label('damaged?')?->id;
        $this->assertSame(
            1,
            \count(\array_filter($links, static fn (string $link): bool => \str_starts_with($link, (string) $damaged.'>') && \str_ends_with($link, ':yes'))),
            'a repeat loop returns from its test',
        );
        $bars = \array_filter($graph->nodes, static fn (Node $node): bool => Shape::Box === $node->shape);
        $this->assertCount(6, $bars, 'a bar to fork and one to join, three times');
        $this->assertSame('Warehouse', $graph->clusters[0]->label);
        $this->assertSame([$label('Store')?->id], $graph->clusters[0]->nodes);
        $this->assertSame([$label('Label')?->id], $graph->clusters[0]->clusters[0]->nodes);
        $this->assertSame('#ffffff', \array_last($graph->nodes)?->stroke, 'stop is a ringed circle');
    }

    public function testANoteWithNothingBeforeItAndAStepOfNoKnownKindStandAlone(): void
    {
        $graph = new ActivityDiagram([new Note('first'), new class implements Step {}, new Action('a')])->graph();

        $this->assertCount(2, $graph->nodes);
        $this->assertSame([], $graph->edges);
    }
}
