<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Style;

use Janalis\Viz\PlantUML\Style\SkinParam;
use Janalis\Viz\PlantUML\Style\StyleSignature;
use PHPUnit\Framework\TestCase;

final class SkinParamTest extends TestCase
{
    private const array TABLE = [
        'arrowcolor' => [[['arrow'], 'LineColor']],
        'notebordercolor' => [[['note'], 'LineColor']],
        'classshadowing' => [[['class'], 'Shadowing']],
        'hyperlinkunderline' => [[['root'], 'HyperlinkUnderlineThickness']],
    ];

    public function testValuesAreSpelledAsPlantUmlSpellsThem(): void
    {
        $this->assertSame([['arrow||-1|', 'LineColor', 'red']], self::read('arrowColor', 'red'));
        $this->assertSame([['class||-1|', 'Shadowing', '3']], self::read('classShadowing', 'true'));
        $this->assertSame([['class||-1|', 'Shadowing', '0']], self::read('classShadowing', 'FALSE'));
        $this->assertSame([['root||-1|', 'HyperlinkUnderlineThickness', '0']], self::read('hyperlinkUnderline', 'false'));
        $this->assertSame([['root||-1|', 'HyperlinkUnderlineThickness', '1']], self::read('hyperlinkUnderline', 'true'));
        $this->assertSame([['root||-1|', 'HyperlinkUnderlineThickness', '2']], self::read('hyperlinkUnderline', '2'));
        $this->assertSame([['arrow||-1|', 'LineColor', 'right']], self::read('arrowColor', 'right:right'));
        // As in PlantUML, the dash spelling is then read as a compound value, keeping its first part.
        $this->assertSame([['arrow||-1|', 'LineColor', '1']], self::read('arrowColor', 'dotted'));
        $this->assertSame([['arrow||-1|', 'LineColor', '7']], self::read('arrowColor', 'dashed'));
        $this->assertSame([['arrow|a,b|-1|', 'LineColor', 'red']], self::read('arrowColor<<a & b>>', 'red'));
        $this->assertSame([['arrow||-1|', 'LineColor', 'red']], self::read('arrowColor<<>>', 'red'));
        $this->assertSame([['arrow|a|-1|', 'LineColor', 'red']], self::read('arrow<<a>>Color', 'red'), 'a stereotype within the key, as a block of a stereotype writes it');
        $this->assertSame(
            [
                ['arrow|a|-1|', 'LineColor', 'red'],
                ['arrow|b|-1|', 'LineColor', 'red'],
            ],
            self::read('arrowColor<<a>><<b>>', 'red'),
        );
    }

    public function testCompoundValuesSetLineAndTextToo(): void
    {
        $this->assertSame(
            [
                ['note||-1|', 'LineStyle',     '7;7'],
                ['note||-1|', 'FontColor',     'blue'],
                ['note||-1|', 'LineThickness', '2'],
                ['note||-1|', 'LineColor',     'red'],
            ],
            self::read('noteBorderColor', 'red;line.dashed;text:blue;line.bold;other'),
        );
        $this->assertSame([['note||-1|', 'LineStyle', '1;3']], self::read('noteBorderColor', ';line.dotted'));
        $this->assertSame([['note||-1|', 'FontColor', 'green']], self::read('noteBorderColor', 'text:green'));
    }

    public function testShadowsWithoutAnEntryStillApply(): void
    {
        $this->assertSame([['root||-1|', 'Shadowing', '3']], self::read('shadowing', 'yes'));
        $this->assertSame([['note,root||-1|', 'Shadowing', '0']], self::read('noteShadowing', 'no'));
        $this->assertSame([['root||-1|', 'Shadowing', '2']], self::read('shadowing', '2'));
        $this->assertSame([], self::read('nothing', 'x'));
    }

    /** @return list<array{string, string, string}> */
    private static function read(string $key, string $value): array
    {
        return \array_map(static fn (array $declaration): array => [$declaration[0]->key(), $declaration[1], $declaration[2]], SkinParam::declarations($key, $value, self::TABLE));
    }

    public function testSignaturesAreBuiltFromTheTablesNames(): void
    {
        $this->assertSame('arrow||-1|', StyleSignature::of(['arrow'])->key());
    }
}
