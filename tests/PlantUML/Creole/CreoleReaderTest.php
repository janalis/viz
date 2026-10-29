<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\PlantUML\Creole\CreoleFont;
use Janalis\Viz\PlantUML\Creole\CreoleReader;
use Janalis\Viz\PlantUML\Creole\PlantUmlCreole;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreoleReaderTest extends TestCase
{
    public function testTextIsAsWideAsJavaSetsItInItsFamilyAndFace(): void
    {
        $line = '.'.\str_repeat('a', times: 20).'.';

        // As the jar writes their lengths: in Java's serif, its monospaced, and its serif bold.
        $this->assertEqualsWithDelta(
            [131.277, 185.432, 147.0],
            [new CreoleFont(14.0, 'serif')->width($line), new CreoleFont(14.0, 'monospaced')->width($line), new CreoleFont(14.0, 'serif', bold: true)->width($line)],
            0.01,
        );
    }

    public function testMarkupSetsEachStretchInItsFontInOrder(): void
    {
        $this->assertSame(
            [
                ['Mixed', FontFace::Regular,    '#000000'],
                ['bold',  FontFace::Bold,       '#000000'],
                ['both',  FontFace::BoldItalic, '#000000'],
                ['red',   FontFace::Regular,    '#ff0000'],
            ],
            \array_map(static fn (Mark $mark): array => [\trim($mark->text), $mark->font, $mark->stroke], self::texts('Mixed **bold //both//** <color:red>red</color>')),
        );
    }

    public function testAHeadingGrowsAgainForTheMarkupWithinIt(): void
    {
        [$x, $y] = self::texts('= **x** y');

        $this->assertEqualsWithDelta(22 / 18, $x->fontScale / $y->fontScale, 0.000_1);
    }

    public function testNumbersCountAtEachDepthAcrossTheSheet(): void
    {
        $this->assertSame(['1.', 'a', 'text', '2.', 'b', '1.', 'c', '3.', 'd'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts("# a\ntext\n# b\n## c\n# d")));
    }

    public function testEscapedMarkupStaysText(): void
    {
        $this->assertSame(['Not **bold** but «stereo» ©'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts('Not ~**bold~** but <<stereo>> &#169;')));
    }

    public function testASpriteAndALinkWithNothingToShowDrawNothing(): void
    {
        // As PlantUML: a sprite a Creole block cannot define is left out; a link with neither label nor target shows nothing.
        $this->assertSame(['a ', ' b'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts('a <#red$sprite> b')));
        $this->assertSame(['a ', ' b'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts('a [[ {} ]] b')));
    }

    public function testACellsEscapedBackslashIsOne(): void
    {
        $this->assertSame(['a\\b'], \array_map(static fn (Mark $mark): string => \trim($mark->text), self::texts('| a\\\\b |')));
    }

    public function testASheetStandsOnItsFoot(): void
    {
        $this->assertSame(0.0, CreoleReader::sheet(['a'], new CreoleFont())->altitude());
    }

    public function testATableRowIsAsHighAsItsHighestCell(): void
    {
        $sheet = CreoleReader::sheet(['| a | one\ntwo |', '| b | c |'], new CreoleFont());
        $line = new CreoleFont()->line();

        $this->assertEqualsWithDelta((3 * $line) + 4, $sheet->size()[1], 0.001);
    }

    public function testATreeSetsEachLevelFurtherIn(): void
    {
        $texts = self::texts("|_ root\n  |_ child\n    |_ leaf");

        $this->assertSame(['root', 'child', 'leaf'], \array_map(static fn (Mark $mark): string => \trim($mark->text), $texts));
        $this->assertLessThan($texts[1]->points[0][0], $texts[0]->points[0][0]);
        $this->assertLessThan($texts[2]->points[0][0], $texts[1]->points[0][0]);
    }

    public function testEveryLineIsText(): void
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startcreole\ntitle Not a title\n@endcreole");

        $this->assertInstanceOf(PlantUmlCreole::class, $diagram);
        $this->assertInstanceOf(Graph::class, $diagram->model());
        $this->assertSame(['title', 'Not', 'a', 'title'], \explode(' ', \implode(' ', \array_map(static fn (Mark $mark): string => $mark->text, self::marks($diagram)))));
    }

    public function testAnEmojiStandsOnTheLineAsPlantUmlSetsIt(): void
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startcreole\nHello <:smile:> world\n<#green:heart:> <:nope:>\n**bold** <:smile:{scale=2}>\n@endcreole");
        $this->assertInstanceOf(PlantUmlCreole::class, $diagram);
        $marks = $diagram->sheet->marks(0.0, 0.0);
        $face = $marks[1];

        // As the jar draws them: the face 21 wide at 14 points, its foot 3 of its 36 above the line's; a colour forced; one it does not know in red.
        $this->assertEqualsWithDelta(
            [34.604, 0.0, 55.604, 21.0],
            [\min(\array_column($face->points, 0)), \min(\array_column($face->points, 1)), \max(\array_column($face->points, 0)), \max(\array_column($face->points, 1))],
            1e-3,
        );
        $this->assertContains('#007f00', \array_map(static fn (Mark $mark): string => $mark->fill, $marks));
        $this->assertContains(['¿nope?', '#ff0000'], \array_map(static fn (Mark $mark): array => [$mark->text, $mark->stroke], self::marks($diagram)));
        $this->assertEqualsWithDelta(
            114.0,
            Work::run(PlantUmlPipeline::native()->drawing("@startcreole\nHello <:smile:> world\n* item <:1f600:>\n<#green:heart:> alone\n**bold** <:smile:{scale=2}>\n@endcreole"))->height
            / Units::POINT,
            1e-9,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'a horizontal line, which PlantUML fails on' => ['----', 'horizontal line'];
        yield 'an icon' => ['<&star>', 'leaves <&star>'];
        yield 'an image' => ['<img:x.png>', 'leaves <img:x.png>'];
        yield 'a formula' => ['<math>x</math>', 'leaves <math>x</math>'];
        yield 'a diagram within' => ["{{\nA -> B\n}}", 'diagrams within Creole'];
    }

    #[DataProvider('refusals')]
    public function testItLeavesToPlantUmlWhatItDoesNotDraw(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startcreole\n".$lines."\n@endcreole"));
    }

    /** @return list<Mark> */
    private static function texts(string $lines): array
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startcreole\n".$lines."\n@endcreole");
        self::assertInstanceOf(PlantUmlCreole::class, $diagram);

        return self::marks($diagram);
    }

    /** @return list<Mark> */
    private static function marks(PlantUmlCreole $diagram): array
    {
        return \array_values(\array_filter($diagram->sheet->marks(0.0, 0.0), static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
    }
}
