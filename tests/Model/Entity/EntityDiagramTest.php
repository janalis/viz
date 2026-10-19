<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\Entity;

use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Tests\Support\EntitySample;
use PHPUnit\Framework\TestCase;

final class EntityDiagramTest extends TestCase
{
    public function testTheGraphListsAttributesAndLabelsBothCardinalities(): void
    {
        $diagram = EntitySample::diagram();
        $graph = $diagram->graph();

        $this->assertSame(Family::Entity, $diagram->family());
        $this->assertSame('Client "VIP"', $diagram->entities[0]->text());
        $this->assertSame([['string id [PK]', "string full name — legal\nname"]], $graph->nodes[0]->compartments);
        $this->assertSame([[]], $graph->nodes[2]->compartments);
        $this->assertSame(['1 places 0..*', ArrowHead::None, LineStyle::Solid], [$graph->edges[0]->label, $graph->edges[0]->head, $graph->edges[0]->style]);
        $this->assertSame(["0..1 contains\nlines 1..*", LineStyle::Dashed], [$graph->edges[1]->label, $graph->edges[1]->style]);
        $this->assertSame('1..* 0..1', $graph->edges[2]->label);
        $this->assertSame(['sales', 'Sales', ['ORDER']], [$graph->clusters[0]->id, $graph->clusters[0]->label, $graph->clusters[0]->nodes]);
        $this->assertSame(['LINE ITEM'], $graph->clusters[0]->clusters[0]->nodes);
        $this->assertSame('int n [PK, FK, UK]', new Attribute('int', 'n', [Key::Primary, Key::Foreign, Key::Unique])->text());
    }
}
