<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Ftile\Worm;
use Janalis\Viz\PlantUML\Ftile\WormMutation;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WormMutationTest extends TestCase
{
    public function testAStepDownMovesAcrossAndAStepAcrossMovesDown(): void
    {
        $down = WormMutation::of(self::worm([0.0, 0.0], [0.0, 10.0]), delta: 2.0);
        $across = WormMutation::of(self::worm([10.0, 0.0], [0.0, 0.0]), delta: 2.0);

        $this->assertSame([[2.0, 0.0], [2.0, 0.0]], $down->moves);
        $this->assertSame([[[2.0, 0.0], [2.0, 10.0]], 2.0, false], [$down->mute(self::worm([0.0, 0.0], [0.0, 10.0]))->points, $down->textShift(colors: 2), $down->negative()]);
        $this->assertSame([[0.0, 2.0], [0.0, 2.0]], $across->moves);
        $this->assertSame(0.0, $across->textShift(colors: 3), 'nothing across');
    }

    public function testATurnMovesAslantAndALongerWayChainsItsTurns(): void
    {
        $this->assertSame([[0.0, -2.0], [2.0, -2.0], [2.0, 0.0]], WormMutation::of(self::worm([0.0, 0.0], [10.0, 0.0], [10.0, 10.0]), delta: 2.0)->moves);
        $longer = WormMutation::of(self::worm([0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]), delta: 2.0);

        // As PlantUML, a turn whose first move is not the last one's is taken backwards.
        $this->assertSame([[0.0, -2.0], [2.0, -2.0], [-2.0, -2.0], [0.0, -2.0]], $longer->moves);
        $this->assertSame(-2.0, $longer->textShift(colors: 2));
        $downRight = WormMutation::of(self::worm([0.0, 0.0], [0.0, 10.0], [10.0, 10.0]), delta: 1.0);
        $this->assertTrue($downRight->negative());
    }

    /** @return iterable<string, array{Worm}> */
    public static function unknown(): iterable
    {
        yield 'aslant' => [self::worm([0.0, 0.0], [10.0, 10.0])];
        yield 'a point' => [self::worm([0.0, 0.0])];
        yield 'down then down' => [self::worm([0.0, 0.0], [0.0, 10.0], [0.0, 20.0])];
    }

    #[DataProvider('unknown')]
    public function testAWayPlantUmlHasNoMovesForIsLeftToIt(Worm $worm): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw this arrow in several colours'));

        WormMutation::of($worm, delta: 2.0);
    }

    /** @param array{float, float} ...$points */
    private static function worm(array ...$points): Worm
    {
        $worm = new Worm();
        $worm->points = \array_values($points);

        return $worm;
    }
}
