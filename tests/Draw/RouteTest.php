<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\Route;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public function testAPathEndsArrivingFromItsLastDistinctPoint(): void
    {
        $this->assertSame([[3.0, 4.0], [0.6, 0.8]], Route::end([[0.0, 0.0], [3.0, 4.0], [3.0, 4.0]]));
        $this->assertSame([[1.0, 1.0], [0.0, 1.0]], Route::end([[1.0, 1.0], [1.0, 1.0]]));
    }
}
