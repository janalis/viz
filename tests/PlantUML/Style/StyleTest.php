<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Style;

use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testValuesReadAsPlantUmlReadsThem(): void
    {
        $style = new Style([
            'FontSize' => '0',
            'LineThickness' => ' 1.5 ',
            'LineColor' => 'nope',
            'FontColor' => 'automatic',
            'FontStyle' => 'Bold Italic',
            'Padding' => '4 8',
            'Margin' => '1 2 3',
            'LineStyle' => '7;7',
        ]);

        $this->assertSame(1.0, $style->fontSize());
        $this->assertSame(1.5, $style->number('LineThickness'));
        $this->assertSame(2.0, $style->number('Missing', 2.0));
        $this->assertSame('#ffffff', $style->color('LineColor', '#123456'), 'a colour PlantUML does not know is white');
        $this->assertSame('#123456', $style->color('FontColor', '#123456'));
        $this->assertSame('none', $style->color('BackGroundColor'));
        $this->assertSame('x', $style->value('Missing', 'x'));
        $this->assertSame(FontFace::BoldItalic, $style->face());
        $this->assertSame([4.0, 8.0, 4.0, 8.0], $style->sides('Padding'));
        $this->assertSame([1.0, 2.0, 3.0, 2.0], $style->sides('Margin'));
        $this->assertSame([7.0, 7.0], $style->dashes());
    }

    public function testFacesSidesAndDashes(): void
    {
        $this->assertSame(FontFace::Bold, new Style(['FontWeight' => '700'])->face());
        $this->assertSame(FontFace::Italic, new Style(['FontStyle' => 'italic'])->face());
        $this->assertSame(FontFace::Regular, new Style()->face());
        $this->assertSame([5.0, 5.0, 5.0, 5.0], new Style(['Padding' => '5'])->sides('Padding'));
        $this->assertSame([1.0, 2.0, 3.0, 4.0], new Style(['Padding' => '1  2 3 4'])->sides('Padding'));
        $this->assertSame([0.0, 0.0, 0.0, 0.0], new Style(['Padding' => '1.5'])->sides('Padding'));
        $this->assertNull(new Style(['LineStyle' => '0;5'])->dashes());
        $this->assertNull(new Style()->dashes());
    }

    public function testColoursFollowPlantUmlsSpellings(): void
    {
        $this->assertSame('#888888', PlantUmlColor::parse('#8'));
        $this->assertSame('#aabbcc', PlantUmlColor::parse('ABC'));
        $this->assertSame('#123456', PlantUmlColor::parse('#123456'));
        $this->assertSame('#123456', PlantUmlColor::parse('#12345680'));
        $this->assertSame('#ff0000', PlantUmlColor::parse('Red'));
        $this->assertSame('none', PlantUmlColor::parse('transparent'));
        $this->assertSame('#ff0000', PlantUmlColor::parse('red|blue'));
        $this->assertSame('#ff0000', PlantUmlColor::parse('#red/#00f'));
        $this->assertNull(PlantUmlColor::parse('red|nothing'));
        $this->assertNull(PlantUmlColor::parse('#12345'));
        $this->assertNull(PlantUmlColor::parse('nothing'));
    }
}
