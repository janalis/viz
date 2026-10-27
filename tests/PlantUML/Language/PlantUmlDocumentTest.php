<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Language;

use Janalis\Viz\PlantUML\Language\PlantUmlCaption;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlDocumentTest extends TestCase
{
    public function testSharedCommandsAreTakenOut(): void
    {
        $document = self::read(<<<'PUML'
            !pragma layout smetana
            skin rose
            skinparam classBackgroundColor Red
            skinparam class {
              BorderColor blue
              Font {
                Color green
              }

              ArrowColor<<hot>> orange
            }
            skinparam {
              ArrowColor black
            }
            <style>class { FontSize 20 }</style>
            <style>
            class { LineThickness 3 }
            </style>
            title: The "title"
            caption "A caption"
            right header Top
            footer: Bottom
            legend Short
            scale 3/2
            class A
              indented B
            PUML);

        $this->assertSame(['class A', '  indented B'], $document->lines);
        $this->assertSame(['layout' => 'smetana'], $document->pragmas);
        $this->assertSame('Red', $document->param('ClassBackgroundColor'));
        $this->assertSame('blue', $document->param('classbordercolor'));
        $this->assertSame('green', $document->param('classfontcolor'));
        $this->assertSame('orange', $document->param('classarrowcolor<<hot>>'));
        $this->assertSame('black', $document->param('arrowcolor'));
        $this->assertSame('none', $document->param('missing', 'none'));
        $class = $document->styles->style(['root', 'element', 'classDiagram', 'class']);
        $this->assertSame('#ff0000', $class->color('BackGroundColor'));
        $this->assertSame('#0000ff', $class->color('LineColor'));
        $this->assertSame(3.0, $class->number('LineThickness'));
        $this->assertSame(1.5, $document->scale);
        $this->assertEquals(
            [
                'title' => new PlantUmlCaption(['The "title"']),
                'caption' => new PlantUmlCaption(['A caption']),
                'header' => new PlantUmlCaption(['Top'], 'right'),
                'footer' => new PlantUmlCaption(['Bottom']),
                'legend' => new PlantUmlCaption(['Short']),
            ],
            $document->captions,
        );
    }

    public function testCaptionsOverSeveralLines(): void
    {
        $document = self::read(<<<'PUML'
            title
              First
              Second
            end title
            left footer
            Foot
            endfooter
            header
            Head
            end header
            caption
            Cap
            endcaption
            legend bottom right
            Legend
            end legend
            scale 2
            PUML);

        $this->assertEquals(new PlantUmlCaption(['First', 'Second']), $document->captions['title'] ?? null);
        $this->assertEquals(new PlantUmlCaption(['Foot'], 'left'), $document->captions['footer']);
        $this->assertEquals(new PlantUmlCaption(['Head']), $document->captions['header']);
        $this->assertEquals(new PlantUmlCaption(['Cap']), $document->captions['caption']);
        $this->assertEquals(new PlantUmlCaption(['Legend'], 'right', 'bottom'), $document->captions['legend']);
        $this->assertEquals(new PlantUmlCaption(['Top'], vertical: 'top'), self::read("legend top\nTop\nendlegend")->captions['legend']);
        $this->assertEquals(new PlantUmlCaption(['Plain']), self::read("legend\nPlain\nendlegend")->captions['legend']);
        $this->assertSame(2.0, $document->scale);
        // A caption on one line breaks where `\n` breaks it, as PlantUML breaks it.
        $this->assertEquals(new PlantUmlCaption(['M', 'line']), self::read('title M\\nline')->captions['title']);
        $this->assertEquals(new PlantUmlCaption(['H', 'x'], 'left'), self::read('left header H\\nx')->captions['header']);
    }

    public function testLinesThatOnlyLookLikeCommandsStay(): void
    {
        $document = self::read("title\nend title\nheader:\ntitleBar -> x\nlegend: \"\"\ntitle ---");

        $this->assertSame(['header:', 'titleBar -> x', 'title ---'], $document->lines);
        $this->assertEquals(new PlantUmlCaption(['']), $document->captions['legend'], 'an empty quoted legend is still one');
        $this->assertSame('', PlantUmlDocument::text(' ""'));
        $this->assertNull(PlantUmlDocument::text('x'));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'sprite' => ['sprite $x [16x16/4] {'];
        yield 'other skin' => ['skin debug'];
        yield 'scale to a size in units' => ['scale 200 px'];
        yield 'zero scale' => ['scale 0'];
        yield 'bad scale' => ['scale 1.2.3'];
        yield 'zero divisor' => ['scale 1/0'];
        yield 'open title' => ["title\nx"];
        yield 'open skinparam' => ["skinparam {\n a b"];
        yield 'the strict UML style' => ["skinparam {\n  Style StrictUML\n}"];
        yield 'padding around every text' => ["skinparam {\n  Padding 2\n}"];
    }

    #[DataProvider('refused')]
    public function testSharedCommandsLeftToPlantUmlAreRefused(string $lines): void
    {
        $this->expectException(Unsupported::class);

        self::read($lines);
    }

    private static function read(string $lines): PlantUmlDocument
    {
        return PlantUmlDocument::read(PlantUmlSource::parse("@startuml\n".$lines."\n@enduml"));
    }
}
