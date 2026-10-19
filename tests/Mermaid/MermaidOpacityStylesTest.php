<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidColor;
use Janalis\Viz\Mermaid\MermaidCss;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidOpacityStylesTest extends TestCase
{
    public function testOpacitiesParseAsBrowsersClampThem(): void
    {
        $statement = new MermaidStatement('style A', offset: 0, line: 3, column: 2);
        $this->assertSame(
            ['opacity' => 0.5, 'fill-opacity' => 0.25, 'stroke-opacity' => 1.0],
            new MermaidCss('flowchart')->parse('opacity:0.5,fill-opacity:25%,stroke-opacity:2', $statement),
        );
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function colors(): iterable
    {
        yield 'rgba' => ['rgba(255, 0, 0, 0.5)', '#ff000080'];
        yield 'eight digits' => ['#FF000080', '#ff000080'];
        yield 'four digits' => ['#f008', '#ff000088'];
        yield 'hsl' => ['hsl(120, 100%, 25%)', '#008000'];
        yield 'transparent' => ['transparent', '#00000000'];
        yield 'name' => ['RebeccaPurple', '#663399'];
        yield 'dropped' => ['0.5 0.5 0.5', null];
    }

    #[DataProvider('colors')]
    public function testCssColorsKeepTheirAlpha(string $css, ?string $expected): void
    {
        $this->assertSame($expected, MermaidColor::css($css));
    }

    public function testFadingScalesAlphaAndDefersTokens(): void
    {
        $this->assertSame(
            ['#ff000080', '#ff000040', '@mainBkg@50', 'none', null, '#ff0000'],
            [
                MermaidColor::faded('#ff0000', 0.5),
                MermaidColor::faded('#ff000080', 0.5),
                MermaidColor::faded('@mainBkg', 0.5),
                MermaidColor::faded('none', 0.5),
                MermaidColor::faded(null, 0.5),
                MermaidColor::faded('#ff0000', 1.0),
            ],
        );
        $this->assertSame(
            ['#0000ff40', '#ff000080', '#00800080'],
            MermaidCss::faded(['opacity' => 0.5, 'fill-opacity' => 0.5], '#0000ff', '#ff0000', '#008000'),
            'fill-opacity fades the fill alone; opacity fades every color',
        );
        $this->assertSame(['#0000ff', '#ff000033', '#008000'], MermaidCss::faded(['stroke-opacity' => 0.2], '#0000ff', '#ff0000', '#008000'));
    }

    public function testNodeGroupAndEdgeTransparencyReachTheExports(): void
    {
        $source = <<<'MMD'
            flowchart TD
            subgraph G[Group]
            A[Half] --> B[Clear]
            end
            style A fill:#0000ff,stroke:#ff0000,opacity:50%
            style B fill:rgba(0\, 128\, 0\, 0.5)
            style G fill:#ffff00,fill-opacity:0.25
            linkStyle 0 stroke:#ff00ff,stroke-opacity:0.5
            MMD;
        $svg = NativeFixtureExport::render($source, Format::Svg);
        foreach (['fill="#0000ff80"', 'stroke="#ff000080"', 'fill="#00800080"', 'fill="#ffff0040"', 'stroke="#ff00ff80"'] as $needle) {
            $this->assertStringContainsString($needle, $svg);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }

    public function testTreemapClassesFadeTheirTiles(): void
    {
        $svg = NativeFixtureExport::render("treemap-beta\n\"A\"\n  \"B\": 10:::half\nclassDef half fill:#0000ff,stroke:#ff000080,opacity:0.5", Format::Svg);
        $this->assertStringContainsString('fill="#0000ff80"', $svg);
        $this->assertStringContainsString('stroke="#ff000040"', $svg);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'word' => ['opacity:half'];
        yield 'negative' => ['fill-opacity:-0.5'];
        yield 'unit' => ['stroke-opacity:0.5px'];
    }

    #[DataProvider('invalid')]
    public function testMalformedOpacitiesRetainLocatedErrors(string $style): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Line 4, column 3: Invalid flowchart opacity');
        new MermaidCss('flowchart')->parse($style, new MermaidStatement('style A', offset: 20, line: 3, column: 2));
    }
}
