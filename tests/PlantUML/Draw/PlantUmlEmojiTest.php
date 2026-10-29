<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\FillRule;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlEmoji;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\PlantUmlHsluv;
use PHPUnit\Framework\TestCase;

final class PlantUmlEmojiTest extends TestCase
{
    public function testAnEmojiIsFoundByItsCodeOrItsNameInAnyCase(): void
    {
        $this->assertSame('1f604', PlantUmlEmoji::code('smile'));
        $this->assertSame('1f600', PlantUmlEmoji::code('1F600'));
        $this->assertNull(PlantUmlEmoji::code('nope'));
    }

    public function testAnEmojisShapesAreDrawnWhereTheJarDrawsThem(): void
    {
        // As the jar draws <:1f600:> in a note at 13 points: a circle, two paths, two ellipses.
        [$face, $mouth, , $eye] = PlantUmlEmoji::marks('1f600', 105.859, 20.44, 19.5);

        $this->assertEqualsWithDelta([105.859, 125.359, 20.44, 39.94], self::box($face), 1e-3);
        // A circle edged in its own colour.
        $this->assertSame(['#ffcc4d', '#ffcc4d'], [$face->fill, $face->stroke]);
        $this->assertEqualsWithDelta([109.651, 121.567, 31.255, 37.232], self::box($mouth), 1e-3);
        $this->assertSame([FillRule::NonZero, 'none'], [$mouth->fillRule, $mouth->stroke]);
        $this->assertEqualsWithDelta([111.005, 113.713, 25.857, 29.648], self::box($eye), 1e-3);
    }

    public function testShapesTurnAsWrittenAndTakeTheirGroupsColour(): void
    {
        // A circle turned, as the jar scales its centre and radius along each axis alone: its centre at 8.186, 59.381, 0.107 round.
        $circle = PlantUmlEmoji::marks('1f92e', 13.0, 31.5, 19.5)[5];
        $this->assertEqualsWithDelta([8.079, 8.293, 59.274, 59.488], self::box($circle), 1e-3);
        // An ellipse through a matrix, filled in its group's colour.
        $ellipse = PlantUmlEmoji::marks('1f96f', 13.0, 12.0, 19.5)[8];
        $this->assertSame('#d99e82', $ellipse->fill);
        $this->assertEqualsWithDelta([21.842, 14.258], $ellipse->points[16], 1e-3);
        // A path filled with nothing is left out, and one without a colour is black.
        $this->assertCount(17, PlantUmlEmoji::marks('1f921', 0.0, 0.0, 36.0));
        $this->assertSame('#000000', PlantUmlEmoji::marks('1f38b', 0.0, 0.0, 36.0)[0]->fill);
    }

    public function testAForcedColourShadesAnEmojiAsTheJarDoes(): void
    {
        $fills = static fn (?string $forced): array => \array_map(static fn (Mark $mark): string => $mark->fill, PlantUmlEmoji::marks('1f604', 0.0, 0.0, 19.5, $forced));

        $this->assertSame(['#ffcc4d', '#664500', '#ffffff'], $fills(null));
        // A grey forced makes each colour its grey; any other lightens it from the picture's darkest.
        $this->assertSame(['#cccccc', '#474747', '#ffffff'], $fills('#000000'));
        $this->assertSame(['#00d300', '#007f00', '#00f500'], $fills('#008000'));
        $this->assertSame(['#9999ff', '#0000fe', '#c3c3ff'], $fills('#0000ff'));
        $this->assertSame([0.0, 0.0, 0.0], PlantUmlHsluv::fromRgb([0.0, 0.0, 0.0]));
        $this->assertSame([0.0, 0.0, 0.0], PlantUmlHsluv::toRgb([120.0, 50.0, 0.0]));
        $this->assertEqualsWithDelta([1.0, 1.0, 1.0], PlantUmlHsluv::toRgb(PlantUmlHsluv::fromRgb([1.0, 1.0, 1.0])), 1e-6);
    }

    public function testAnEmojiInTextIsSetAsTheJarSetsIt(): void
    {
        $text = new PlantUmlText(['hi <:1f600:> there <:nope:>'], 13.0);
        $marks = $text->marks(89.92, 20.44, 0.0, 'left');

        // Its foot 3 of its 36 above the line's: the line as tall as the emoji and that.
        $this->assertEqualsWithDelta(21.125, $text->size()[1], 1e-9);
        $this->assertEqualsWithDelta([105.859, 125.359, 20.44, 39.94], self::box($marks[1]), 1e-3);
        // One PlantUML does not know, its name in red.
        $unknown = $marks[\count($marks) - 1];
        $this->assertSame(['¿nope?', '#ff0000'], [$unknown->text, $unknown->stroke]);
        // With no text on its line, from the tallest's top to the lowest foot, as the jar sets them; scaled; black's written forms the text's colour.
        $this->assertEqualsWithDelta([19.5, 19.5], new PlantUmlText(['<:smile:>'], 13.0)->size(), 1e-9);
        $this->assertEqualsWithDelta([58.5, 40.625], new PlantUmlText(['<:smile:><:smile:{scale=2}>'], 13.0)->size(), 1e-9);
        $this->assertSame('#474747', new PlantUmlText(['<#0:smile:>'], 13.0)->marks(0.0, 0.0, 0.0)[1]->fill);
        $this->assertSame('#0000fe', new PlantUmlText(['<:smile:{color=blue}>'], 13.0)->marks(0.0, 0.0, 0.0)[1]->fill);
        $this->assertSame('#664500', new PlantUmlText(['<#transparent:smile:>'], 13.0)->marks(0.0, 0.0, 0.0)[1]->fill);
    }

    /** @return array{float, float, float, float} */
    private static function box(Mark $mark): array
    {
        $xs = \array_column($mark->points, 0);
        $ys = \array_column($mark->points, 1);

        return [\min($xs), \max($xs), \min($ys), \max($ys)];
    }
}
