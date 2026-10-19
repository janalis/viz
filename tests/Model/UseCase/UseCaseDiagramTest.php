<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\UseCase;

use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Tests\Support\UseCaseSample;
use PHPUnit\Framework\TestCase;

final class UseCaseDiagramTest extends TestCase
{
    public function testTheGraphDrawsActorsEllipsesAndTheirLinks(): void
    {
        $diagram = UseCaseSample::diagram();
        $graph = $diagram->graph();

        $this->assertSame(Family::UseCase, $diagram->family());
        $this->assertSame([Shape::Actor, "«Employee»\nSales \"clerk\""], [$graph->nodes[1]->shape, $graph->nodes[1]->label]);
        $this->assertSame([Shape::Ellipse, 'Browse catalog'], [$graph->nodes[3]->shape, $graph->nodes[3]->label]);
        $this->assertSame('Customer', $diagram->actors[0]->text());
        $this->assertSame('Audit', $diagram->useCases[3]->text());
        $this->assertSame(['Shop', 'Web shop', ['Browse', 'Pay', 'Clerk']], [$graph->clusters[0]->id, $graph->clusters[0]->label, $graph->clusters[0]->nodes]);
        $this->assertSame('Bare', new Boundary('Bare')->text());
        $this->assertSame([ArrowHead::None, ArrowHead::Arrow], [$graph->edges[2]->head, $graph->edges[2]->tail]);
        $this->assertSame(['«include»', LineStyle::Dashed], [$graph->edges[8]->label, $graph->edges[8]->style]);
        $this->assertSame('«extend»', $graph->edges[9]->label);
        $this->assertSame(ArrowHead::Triangle, $graph->edges[10]->head);
        $this->assertCount(13 + 1, $graph->edges, 'every link and the attached note');
        $this->assertSame('note 0', $graph->edges[13]->from);
    }
}
