<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Language;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use PHPUnit\Framework\TestCase;

final class PlantUmlNoteTest extends TestCase
{
    public function testItsSideTurnsWithALayoutLeftToRightAsPlantUmlsPositionTurns(): void
    {
        $side = static fn (string $position, Direction $direction): string => new PlantUmlNote('n', [], 'A', $position, direction: $direction)->side();

        $this->assertSame(['right', 'left', 'top', 'bottom'], \array_map(static fn (string $position): string => $side($position, Direction::TopBottom), ['right', 'left', 'top', 'bottom']));
        $this->assertSame(['bottom', 'top', 'left', 'right', 'over'], \array_map(static fn (string $position): string => $side($position, Direction::LeftRight), [
            'right',
            'left',
            'top',
            'bottom',
            'over',
        ]));
    }
}
