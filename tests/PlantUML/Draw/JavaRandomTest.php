<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\PlantUML\Draw\JavaRandom;
use PHPUnit\Framework\TestCase;

final class JavaRandomTest extends TestCase
{
    public function testItDrawsJavasNumbers(): void
    {
        // As `new java.util.Random(12345L).nextDouble()` three times, and with the seed PlantUML gives an 87 by 46 cloud.
        $random = new JavaRandom(12_345);
        $this->assertEqualsWithDelta([0.361_803_107_160_471_8, 0.932_993_485_288_541, 0.833_091_348_971_023_7], [$random->nextDouble(), $random->nextDouble(), $random->nextDouble()], 1e-15);
        $this->assertEqualsWithDelta(0.245_418_733_179_318_5, new JavaRandom(87 + (7919 * 46))->nextDouble(), 1e-15);
    }
}
