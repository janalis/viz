<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\Shadow;
use PHPUnit\Framework\TestCase;

final class ShadowTest extends TestCase
{
    public function testOnlyFilledOutlinesCastAShadowAndTakeItsRoom(): void
    {
        $box = new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]], fill: '#ffffff');
        $ring = new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], fill: 'none');
        $line = new Mark(MarkKind::Polyline, [[0.0, 0.0], [10.0, 10.0]]);

        $marks = Shadow::cast([$box, $ring, $line], 3.0);

        $this->assertCount(5, $marks);
        $this->assertSame([[3.0, 3.0], [13.0, 3.0], [13.0, 13.0], [3.0, 13.0]], $marks[0]->points);
        $this->assertSame([[15.0, 15.0], [15.0, 15.0]], $marks[1]->points, 'twice the distance past, 1 short as a rectangle');
        $this->assertSame([$box, $ring, $line], \array_slice($marks, offset: 2));
        $this->assertSame([$box], Shadow::cast([$box], 0.0));
    }
}
