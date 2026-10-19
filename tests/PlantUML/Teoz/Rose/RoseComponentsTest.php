<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Teoz\Rose;

use Janalis\Viz\PlantUML\Sequences\SequenceBox;
use Janalis\Viz\PlantUML\Sequences\SequenceParticipant;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\PlantUML\Teoz\Rose\ActiveLine;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;
use PHPUnit\Framework\TestCase;

final class RoseComponentsTest extends TestCase
{
    public function testComponentsDrawInTheirOwnLayerOnly(): void
    {
        $rose = new Rose(StyleSheet::skin());
        $area = new Area(40.0, 30.0);
        foreach (['actor', 'queue', 'participant'] as $type) {
            $this->assertSame([], $rose->participant(new SequenceParticipant('A', ['A'], $type), head: true)->marks(0.0, 0.0, $area, Layer::Background));
        }
        $this->assertSame([], $rose->englober(new SequenceBox(['box']))->marks(0.0, 0.0, $area, Layer::Foreground));
        $this->assertSame([], $rose->destroy()->marks(0.0, 0.0, $area, Layer::Background));
        $active = new ActiveLine(new Pen(), '#ffffff');
        $this->assertSame([10.0, 0.0], $active->size());
        $this->assertSame([], $active->marks(0.0, 0.0, $area, Layer::Background));
        $this->assertSame([], $active->marks(0.0, 0.0, new Area(10.0, 0.0), Layer::Foreground));
    }

    public function testDashesAreReadAsPlantUmlReadsThem(): void
    {
        $this->assertSame([[5.0, 5.0], [1.0, 4.0], [2.0, 3.0], null, null, null], [Pen::dashes('5'), Pen::dashes('1-4'), Pen::dashes('2;3'), Pen::dashes('0'), Pen::dashes(''), Pen::dashes('dotted')]);
    }
}
