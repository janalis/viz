<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Style;

use Janalis\Viz\PlantUML\Style\StyleParser;
use Janalis\Viz\PlantUML\Style\StyleSignature;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StyleParserTest extends TestCase
{
    public function testSelectorsNestListAndCarryStereotypesDepthsAndStars(): void
    {
        $declarations = self::read(<<<'CSS'
            <style>
            // a comment
            /' another '/ /* and another */
            root {
              --accent: #123;
              FontColor: var(--accent);
              BackGroundColor var(--missing)
              Unknown 3;
              class, interface
              {
                LineColor "dark red", blue;
                Margin 1 2 : 3
              }
              .Fancy_Name { FontSize 12 }
              :element* { Padding 4 }
              node* { depth(2) { Padding 5 } }
              card * { Padding 6 }
            }
            @media (prefers-color-scheme:dark) {
              root { FontColor white }
            }
            </style>
            CSS);

        $this->assertSame(
            [
                ['root||-1|',           'FontColor',       '#123',           false],
                ['root||-1|',           'BackGroundColor', 'var(--missing)', false],
                ['class,root||-1|',     'LineColor',       'dark red, blue', false],
                ['interface,root||-1|', 'LineColor',       'dark red, blue', false],
                ['class,root||-1|',     'Margin',          '1 2:3',          false],
                ['interface,root||-1|', 'Margin',          '1 2:3',          false],
                ['root|fancyname|-1|',  'FontSize',        '12',             false],
                ['element,root||-1|*',  'Padding',         '4',              false],
                ['node,root||2|*',      'Padding',         '5',              false],
                ['card,root||-1|*',     'Padding',         '6',              false],
                ['root||-1|',           'FontColor',       'white',          true],
            ],
            \array_map(static fn (array $declaration): array => [$declaration[0]->key(), $declaration[1], $declaration[2], $declaration[3]], $declarations),
        );
    }

    public function testAnUnclosedQuoteOrCommentRunsToTheEnd(): void
    {
        $this->assertSame('Sans', self::read('root { FontName "Sans')[0][2]);
        $this->assertSame([], self::read('root { /* open'));
        $this->assertSame([], self::read("root { /' open"));
        $this->assertSame([], self::read('@media open'));
        $this->assertSame([], self::read('}}'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'stray open' => ['{'];
        yield 'colon alone' => [': x'];
        yield 'list without block' => ['a, b'];
        yield 'no value' => ['root { FontSize }'];
        yield 'star in value' => ['root { FontSize 1 * }'];
        yield 'colon at end' => ['root { FontSize a: }'];
    }

    #[DataProvider('malformed')]
    public function testMalformedSheetsAreRefused(string $source): void
    {
        $this->expectException(Unsupported::class);

        self::read($source);
    }

    public function testSignaturesMatchByNamesStereotypesDepthAndStar(): void
    {
        $element = StyleSignature::of(['Root', 'class_'], ['Big.One'], depth: 3);
        $this->assertSame(['class', 'root'], $element->names);
        $this->assertSame(['bigone'], $element->stereotypes);
        $this->assertTrue(StyleSignature::of(['root'])->matches($element));
        $this->assertFalse(StyleSignature::of(['node'])->matches($element));
        $this->assertFalse(StyleSignature::of([], ['other'])->matches($element));
        $this->assertTrue(StyleSignature::of([], depth: 3)->matches($element));
        $this->assertFalse(StyleSignature::of([], depth: 2)->matches($element));
        $this->assertTrue(StyleSignature::of([], depth: 2, star: true)->matches($element));
        $this->assertFalse(StyleSignature::of([], depth: 2)->matches(StyleSignature::of([])));
        $this->assertFalse(StyleSignature::of([])->matches(StyleSignature::of([], star: true)));
        $this->assertTrue(StyleSignature::of([])->empty());
        $this->assertFalse($element->empty());
    }

    /** @return list<array{StyleSignature, string, string, bool}> */
    private static function read(string $source): array
    {
        return new StyleParser($source)->declarations();
    }
}
