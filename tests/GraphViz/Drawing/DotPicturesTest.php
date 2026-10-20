<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\GraphViz\DotImage;
use Janalis\Viz\GraphViz\Drawing\DotPictures;
use Janalis\Viz\GraphViz\Html\HtmlImage;
use PHPUnit\Framework\TestCase;

final class DotPicturesTest extends TestCase
{
    /** A node 100 wide and 50 tall, from (10, 20); an image 20 by 10. */
    private const array BOX = [10.0, 20.0, 110.0, 70.0];

    /** @return iterable<string, array{string, string, list<array{float, float}>}> */
    public static function placings(): iterable
    {
        yield 'its own size, in the middle' => ['', '', [[50.0, 40.0], [70.0, 50.0]]];
        yield 'scaled, keeping its shape' => ['true', 'mc', [[10.0, 20.0], [110.0, 70.0]]];
        yield 'stretched across, top left' => ['width', 'tl', [[10.0, 20.0], [110.0, 30.0]]];
        yield 'stretched down, bottom right' => ['height', 'br', [[90.0, 20.0], [110.0, 70.0]]];
        yield 'filling it' => ['BOTH', 'bc', [[10.0, 20.0], [110.0, 70.0]]];
        yield 'middle right' => ['false', 'mr', [[90.0, 40.0], [110.0, 50.0]]];
    }

    /** @param list<array{float, float}> $points */
    #[\PHPUnit\Framework\Attributes\DataProvider('placings')]
    public function testScaleAndPositionSetTheImage(string $scale, string $position, array $points): void
    {
        [$mark] = DotPictures::marks(new DotImage('bytes', 'image/png', 20.0, 10.0), self::BOX, $scale, $position);

        $this->assertSame($points, $mark->points);
        $this->assertSame('data:image/png;base64,'.\base64_encode('bytes'), $mark->text);
    }

    public function testAnHtmlImageIsItsOwnSizeWithNoPorts(): void
    {
        $image = new HtmlImage(new DotImage('bytes', 'image/png', 20.0, 10.0));

        $this->assertFalse($image->empty());
        $this->assertSame([], $image->ports(0.0, 0.0));
        $this->assertSame([[-10.0, -5.0], [10.0, 5.0]], $image->marks(0.0, 0.0)[0]->points);
    }
}
