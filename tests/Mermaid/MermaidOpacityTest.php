<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Draw\SvgBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidOpacityTest extends TestCase
{
    private static function square(string $color, float $opacity): Mark
    {
        return new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]], stroke: 'none', fill: $color, fillOpacity: $opacity);
    }

    public static function opacity(): iterable
    {
        yield 'transparent' => [0.0, 255];
        yield 'half' => [0.5, 128];
        yield 'opaque' => [1.0, 0];
    }

    #[DataProvider('opacity')]
    public function testPngCompositesFillOpacityOnItsBackground(float $opacity, int $green): void
    {
        $drawing = new Drawing(10.0, 10.0, [self::square('#ff0000', $opacity)]);
        $work = new PngBackend()->render($drawing);
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $pixel = \imagecolorsforindex($image, \imagecolorat($image, x: 20, y: 20));
        $this->assertSame(255, $pixel['red']);
        $this->assertEqualsWithDelta($green, $pixel['green'], delta: 1);
        $this->assertEqualsWithDelta($green, $pixel['blue'], delta: 1);
        $svg = SvgBackend::render($drawing);
        \iterator_to_array($svg);
        if (1.0 === $opacity) {
            $this->assertStringNotContainsString('fill-opacity=', $svg->getReturn());

            return;
        }
        $this->assertStringContainsString('fill-opacity="'.$opacity.'"', $svg->getReturn());
    }

    public function testOverlappingTranslucentFillsRemainVisible(): void
    {
        $drawing = new Drawing(10.0, 10.0, [self::square('#ff0000', opacity: 0.5), self::square('#0000ff', opacity: 0.5)]);
        $work = new PngBackend()->render($drawing);
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $pixel = \imagecolorsforindex($image, \imagecolorat($image, x: 20, y: 20));
        $this->assertEqualsWithDelta(128, $pixel['red'], delta: 2);
        $this->assertEqualsWithDelta(64, $pixel['green'], delta: 2);
        $this->assertEqualsWithDelta(192, $pixel['blue'], delta: 2);
    }
}
