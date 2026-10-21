<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Files;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Files\FilesEntry;
use Janalis\Viz\PlantUML\Files\FilesType;
use Janalis\Viz\PlantUML\Files\PlantUmlFiles;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class FilesReaderTest extends TestCase
{
    public function testPathsShareTheirFoldersAndNotesGoInTheLastFilesFolder(): void
    {
        $root = self::read("<note>\nTop\n</note>\n/src/a.php\n/src/lib/b.php\n<note>\nLib\n</note>\nnot a path\n/docs/")->root;

        $this->assertSame(
            [
                [FilesType::Note,   ''],
                [FilesType::Folder, 'src'],
                [FilesType::Folder, 'docs'],
            ],
            self::kinds($root),
        );
        $this->assertSame(
            [
                [FilesType::File,   'a.php'],
                [FilesType::Folder, 'lib'],
            ],
            self::kinds($root->children[1]),
        );
        $this->assertSame(
            [
                [FilesType::File, 'b.php'],
                [FilesType::Note, ''],
            ],
            self::kinds($root->children[1]->children[1]),
        );
        $this->assertSame(['Lib'], $root->children[1]->children[1]->children[1]->note);
    }

    public function testEachRowIsAnEmojiAndItsNameEachFolderFurtherIn(): void
    {
        $drawing = Work::run(self::read("/src/main.php\n/README.md")->drawing());
        $texts = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
        $folders = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#226699' === $mark->fill);
        $pages = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#ccd6dd' === $mark->fill);

        // One open folder, two pages, each drawn from its emoji's paths.
        $this->assertSame([1, 2], [\count($folders), \count($pages)]);
        $this->assertSame(['src', 'main.php', 'README.md'], \array_map(static fn (Mark $mark): string => $mark->text, $texts));
        // A row is 1.625 times the font's size, and 2 more.
        $this->assertEqualsWithDelta($texts[1]->points[0][1] - $texts[0]->points[0][1], $texts[2]->points[0][1] - $texts[1]->points[0][1], 0.001);
    }

    public function testTheModelJoinsEachEntryToItsFolder(): void
    {
        $graph = self::read("/src/main.php\n<note>\nleft out\n</note>")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(
            [
                ['/src',          'src'],
                ['/src/main.php', 'main.php'],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->text()], $graph->nodes),
        );
        $this->assertSame([['/src', '/src/main.php']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges));
    }

    /** @return list<array{FilesType, string}> */
    private static function kinds(FilesEntry $folder): array
    {
        return \array_map(static fn (FilesEntry $entry): array => [$entry->type, $entry->name], $folder->children);
    }

    private static function read(string $lines): PlantUmlFiles
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startfiles\n".$lines."\n@endfiles");
        self::assertInstanceOf(PlantUmlFiles::class, $diagram);

        return $diagram;
    }
}
