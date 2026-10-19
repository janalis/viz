<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\Sequence;

use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Tests\Support\SequenceSample;
use PHPUnit\Framework\TestCase;

final class SequenceDiagramTest extends TestCase
{
    public function testASequenceHasNoGraphForm(): void
    {
        $diagram = SequenceSample::diagram();

        $this->assertSame(Family::Sequence, $diagram->family());
        $this->assertNull($diagram->graph());
        $this->assertSame(['Worker' => true], $diagram->created(), 'found inside fragments too');
        $this->assertSame('Web "app"', $diagram->participants[1]->text());
        $this->assertSame('Db', new Participant('Db')->text());
    }
}
