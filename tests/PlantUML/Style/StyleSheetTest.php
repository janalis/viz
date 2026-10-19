<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Style;

use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class StyleSheetTest extends TestCase
{
    private const array CLASS_NAMES = ['root', 'element', 'classDiagram', 'class'];

    public function testTheDefaultSkinsCascadeAsPlantUmlDraws(): void
    {
        $class = StyleSheet::skin()->style(self::CLASS_NAMES);
        $this->assertSame('#f1f1f1', $class->color('BackGroundColor'));
        $this->assertSame('#181818', $class->color('LineColor'));
        $this->assertSame(14.0, $class->fontSize());
        $this->assertSame('5', $class->value('RoundCorner'));

        $rose = StyleSheet::skin('Rose')->style(self::CLASS_NAMES);
        $this->assertSame('#fefece', $rose->color('BackGroundColor'));
        $this->assertSame('#a80036', $rose->color('LineColor'));
        $this->assertSame(StyleSheet::skin('anything else'), StyleSheet::skin());
    }

    public function testLaterDeclarationsWinAndStereotypesOutrankThem(): void
    {
        $sheet = StyleSheet::skin()->with(<<<'CSS'
            classDiagram { class { LineColor blue } }
            .important { LineColor red }
            class { LineColor green }
            CSS);

        $this->assertSame('#008000', $sheet->style(self::CLASS_NAMES)->color('LineColor'), 'later than classDiagram class');
        $this->assertSame('#ff0000', $sheet->style(self::CLASS_NAMES, ['Important'])->color('LineColor'), 'a stereotype outranks a later plain one');
        $this->assertSame('#181818', StyleSheet::skin()->style(self::CLASS_NAMES)->color('LineColor'), 'the skin itself is untouched');
    }

    public function testDepthsMatchExactlyOrFromTheirOwnWithAStar(): void
    {
        $sheet = StyleSheet::skin()->with(<<<'CSS'
            node { depth(1) { FontSize 20 } }
            node { depth(2)* { FontSize 30 } }
            CSS);

        $this->assertSame(20.0, $sheet->style(['root', 'node'], depth: 1)->fontSize());
        $this->assertSame(30.0, $sheet->style(['root', 'node'], depth: 2)->fontSize());
        $this->assertSame(30.0, $sheet->style(['root', 'node'], depth: 5)->fontSize());
        $this->assertSame(14.0, $sheet->style(['root', 'node'], depth: 0)->fontSize());
        $this->assertSame(14.0, $sheet->style(['root', 'node'])->fontSize());
    }

    public function testSkinParamsTranslateToStyles(): void
    {
        $sheet = StyleSheet::skin()
            ->withSkinParam('ClassBackgroundColor', 'Red')
            ->withSkinParam('classBorderColor<<Hot&Cold>>', 'blue')
            ->withSkinParam('Shadowing', 'true')
            ->withSkinParam('unknownParam', 'x');

        $this->assertSame('#ff0000', $sheet->style(self::CLASS_NAMES)->color('BackGroundColor'));
        $this->assertSame('#181818', $sheet->style(self::CLASS_NAMES, ['hot'])->color('LineColor'));
        $this->assertSame('#0000ff', $sheet->style(self::CLASS_NAMES, ['hot', 'cold'])->color('LineColor'));
        $this->assertSame('3', $sheet->style(self::CLASS_NAMES)->value('Shadowing'));
    }

    public function testAStyleThatDoesNotParseIsLeftToPlantUml(): void
    {
        $this->expectException(Unsupported::class);

        StyleSheet::skin()->with('class { LineColor }');
    }
}
