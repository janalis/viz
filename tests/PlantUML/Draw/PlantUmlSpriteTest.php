<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\PlantUmlSprite;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlSpriteTest extends TestCase
{
    public function testEachEncodingIsReadAsPlantUmlReadsIt(): void
    {
        $this->assertSame([[0, 15], [8, 0]], self::levels(PlantUmlSprite::gray16(['0F', '8']), 16));
        // `A` is 10: 1 and 2 down at 8 levels; 0, 2 and 2 down at 4.
        $this->assertSame([[1, 0], [2, 0], [0, 0]], self::levels(PlantUmlSprite::packed(8, 2, 3, ['A']), 8));
        $this->assertSame([[0], [2], [2]], self::levels(PlantUmlSprite::packed(4, 1, 3, ['A_']), 4));
        $this->assertSame([[0, 1, 2, 3], [3, 2, 1, 0]], self::levels(PlantUmlSprite::compressed(4, 4, 2, 'Os1aOcPcOcG'), 4));
    }

    public function testASpriteIsAPngOfItsShades(): void
    {
        $png = (string) \base64_decode(\substr(PlantUmlSprite::gray16(['0F48'])->png('#ff0000', '#0000ff'), offset: \strlen('data:image/png;base64,')), strict: true);
        $image = \imagecreatefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame([4, 1], [\imagesx($image), \imagesy($image)]);
        // The faintest fade out; the others are opaque shades from the background to the colour.
        $this->assertSame([127, 0, 0, 0], \array_map(static fn (int $x): int => (\imagecolorat($image, $x, y: 0) >> 24) & 0x7F, [0, 1, 2, 3]));
        $this->assertSame([0, 0, 255], self::rgb($image, 1));
        $this->assertSame([187, 0, 68], self::rgb($image, 2));
    }

    public function testATextDrawsItsSpritesStandingOnItsLines(): void
    {
        $sheet = StyleSheet::skin()->withSprite('s', PlantUmlSprite::gray16(\array_fill(0, count: 26, value: \str_repeat('F', times: 13))));
        $text = PlantUmlText::styled(['a <$s> b <$s{scale=2,color=red}> <$nope>', '<#blue$s*0.5>'], $sheet->style(['root']));

        [$width, $height] = $text->size();
        $marks = $text->marks(0.0, 0.0, $width);
        $images = \array_values(\array_filter($marks, static fn ($mark): bool => MarkKind::Image === $mark->kind));

        $this->assertCount(3, $images);
        $this->assertEqualsWithDelta(
            [[(14 * 2 * 13) / 13, 56.0]],
            [[$images[1]->points[1][0] - $images[1]->points[0][0], $images[1]->points[1][1]]],
            0.001,
            'a scale of 2 at 14 points, standing on the line it makes as tall',
        );
        $this->assertEqualsWithDelta(56.0 + PlantUmlText::lineHeight(14.0), $height, 0.001, 'a half-scaled sprite lower than the line');
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'no data' => ["sprite \$s {\n}", 'The PlantUML sprite s has no data'];
        yield 'colours' => ["sprite \$s [2x2/color] {\nFFF FFF\n}", 'The native PlantUML renderer leaves sprites of colours to PlantUML'];
        yield 'two levels' => ["sprite \$s [2x2/2] {\n00\n}", 'PlantUML allows sprites of 4, 8 or 16 gray levels only'];
        yield 'no hex digit' => ["sprite \$s {\n0G\n}", 'The PlantUML sprite has a pixel that is no hex digit: 0G'];
        yield 'not deflated' => ['sprite $s [2x2/16z] ABCD', 'The PlantUML sprite cannot be decoded'];
        yield 'of a library not under MIT' => ['sprite $s #logos#php', 'The native PlantUML renderer leaves the standard library logos to PlantUML'];
        yield 'of SVG' => ['sprite $s <svg viewBox="0 0 1 1"></svg>', 'The native PlantUML renderer leaves sprite to PlantUML'];
    }

    #[DataProvider('refused')]
    public function testWhatPlantUmlRefusesOrDrawsItselfIsLeftToIt(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        PlantUmlDocument::read(PlantUmlSource::parse("@startuml\n".$lines."\nclass A\n@enduml"));
    }

    public function testSpritesAreReadOnOneLineOrSeveral(): void
    {
        $document = PlantUmlDocument::read(PlantUmlSource::parse("@startuml\nsprite \$a {\n  0F\n\n  F0\nend sprite\nsprite b [4x2/4z] Os1aOcPcOcG\nsprite \$c [2x3/8] {\nAA\n}\nclass A\n@enduml"));

        $this->assertSame(['class A'], $document->lines);
        $this->assertSame(['a', 'b', 'c'], \array_keys($document->styles->style(['root'])->sprites));
    }

    /** @return list<list<int>> */
    private static function levels(PlantUmlSprite $sprite, int $levels): array
    {
        $png = (string) \base64_decode(\substr($sprite->png('#ffffff', '#000000'), offset: \strlen('data:image/png;base64,')), strict: true);
        $image = \imagecreatefromstring($png);
        self::assertNotFalse($image);
        $rows = [];
        for ($y = 0; $y < $sprite->height; ++$y) {
            $row = [];
            for ($x = 0; $x < $sprite->width; ++$x) {
                $row[] = (int) \round(((255 - (\imagecolorat($image, $x, $y) & 0xFF)) * ($levels - 1)) / 255);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array{int, int, int} */
    private static function rgb(\GdImage $image, int $x): array
    {
        $color = \imagecolorat($image, $x, y: 0);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }
}
