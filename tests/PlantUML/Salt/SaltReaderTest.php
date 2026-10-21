<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Salt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\Salt\PlantUmlSalt;
use Janalis\Viz\PlantUML\Salt\SaltButton;
use Janalis\Viz\PlantUML\Salt\SaltLabel;
use Janalis\Viz\PlantUML\Salt\SaltTokens;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaltReaderTest extends TestCase
{
    public function testLinesAreCutAtPipesAndBracesEachBlockOpeningATokenOfItsOwn(): void
    {
        $tokens = new SaltTokens(['{+ Login | "x"', 'a \| b | c }']);
        $read = [];
        for ($index = 0; $index < 6; ++$index) {
            $read[] = $tokens->next();
        }

        $this->assertSame([['{+', false], ['Login', false], ['"x"', true], ['a | b', false], ['c', false], ['}', true]], $read);
    }

    public function testRowsAreAsTallAsALineAnd2MoreAndColumnsAsWideAsTheirWidest(): void
    {
        [$width, $height] = self::read("{\n[This is my button] | a\nb | *\n}")->root->size();

        // The button: 17 characters of 8, its margins and border, and 2 more for its column.
        $this->assertEqualsWithDelta(145 + 2 + new SaltLabel(['a'])->size()[0] + 2, $width, 1e-9);
        $this->assertEqualsWithDelta((2 * (SaltLabel::LINE + 2)) + 9, $height, 1e-9);
    }

    public function testAButtonIsAsWideAsItsTextOr8ACharacter(): void
    {
        $this->assertEqualsWithDelta([(8 * 4) + 9, SaltLabel::LINE + 9], new SaltButton('  OK')->size(), 1e-9);
    }

    public function testATitledGroupBoxWritesItsTitleOnItsTopRule(): void
    {
        $drawing = Work::run(self::read("{^\"My box\"\nLogin | \"name\"\n}")->drawing());
        $texts = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));

        $this->assertSame(['Login', 'name', 'My'], \array_map(static fn (Mark $mark): string => \explode(' ', $mark->text)[0], $texts));
    }

    public function testMenusOpenOverTheWidgetsAfterThem(): void
    {
        $drawing = Work::run(self::read("{\n{* File | Edit\nFile | Open | - | Quit }\n[Below]\n}")->drawing());
        $texts = \array_values(\array_map(static fn (Mark $mark): string => $mark->text, \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));

        $this->assertSame(['File', 'Edit', 'Below', 'Open', 'Quit'], $texts);
    }

    public function testAMarkupOnlyTextIsWrittenAsItIs(): void
    {
        $drawing = Work::run(self::read("{\n\"****\"\n}")->drawing());

        $this->assertContains('****', \array_map(static fn (Mark $mark): string => $mark->text, $drawing->marks));
    }

    public function testTheModelListsEachWidgetsText(): void
    {
        $graph = self::read("{\n{T\n+ World\n++ Europe\n}\n[OK] | * \n}")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['World', 'Europe', '[OK]'], \array_map(static fn (Node $node): string => $node->text(), $graph->nodes));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'no block' => ['hello', 'A salt diagram starts with a block'];
        yield 'the old salt keyword' => ["salt\n{\na\n}", 'This is not needed anymore.'];
        yield 'a block not closed' => ["{\na", 'A salt block not closed'];
        yield 'a picture never drawn' => ["{\n<<nope>>\n}", 'No picture nope'];
        yield 'a lone quote' => ["{\n\"\n}", 'nothing inside'];
        yield 'a star first' => ["{\n* | a\n}", 'A * with no widget before it'];
        yield 'a menu item of no entry' => ["{\n{* File\n Edit | x\n}\n}", 'No menu entry Edit'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startsalt\n".$lines."\n@endsalt"));
    }

    private static function read(string $lines): PlantUmlSalt
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startsalt\n".$lines."\n@endsalt");
        self::assertInstanceOf(PlantUmlSalt::class, $diagram);

        return $diagram;
    }
}
