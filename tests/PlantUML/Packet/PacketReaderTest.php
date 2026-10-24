<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Packet;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Packet\PlantUmlPacket;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PacketReaderTest extends TestCase
{
    public function testFieldsAreReadByTheirBits(): void
    {
        $packet = self::read(<<<'PUML'
            packetdiag {
              colwidth = 8
              node_height = 40
              scale_direction = rtl
              scale_interval = 20
              same_height = true
              0-3: A
              4: B [len = 2, height = 3]
              * "C" [len = 6]
              12: D
            }
            PUML);

        // Quotes are kept, as PlantUML keeps them.
        $this->assertSame([[0, 3, 1, 'A'], [4, 5, 3, 'B'], [6, 11, 1, '"C"'], [12, 12, 1, 'D']], $packet->fields);
        $this->assertSame([8, 40.0, 8, true, true], [$packet->columns, $packet->bitHeight, $packet->interval, $packet->rightToLeft, $packet->sameHeight]);
    }

    public function testItIsSizedAsPlantUmlSizesIt(): void
    {
        $size = static function (int $font, string $fields): array {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startpacketdiag\nskinparam defaultFontSize {$font}\n{$fields}\n@endpacketdiag"));

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sizes them: rounded down to whole points, and 1 more.
        $this->assertSame([[237.0, 95.0], [171.0, 96.0], [735.0, 142.0]], [$size(8, fields: '0-7: A'), $size(10, fields: '0-3: A'), $size(14, fields: '0-31: A')]);
    }

    public function testAFieldPastARowsEndIsCarriedOn(): void
    {
        $drawing = Work::run(self::read("colwidth = 4\n0-1: A\n2-11: B")->drawing());
        $boxes = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind));
        $widths = \array_map(static fn ($mark): float => \round((\max(\array_column($mark->points, 0)) - \min(\array_column($mark->points, 0))) / Units::POINT), $boxes);

        // Bits 42 wide: A and the start of B, then B over a whole row, then its last two bits — drawn as wide as a row, as PlantUML draws them.
        $this->assertSame([84.0, 84.0, 168.0, 168.0], $widths);
    }

    public function testTheScaleIsNumberedAtItsInterval(): void
    {
        $drawing = Work::run(self::read('0-15: A')->drawing());
        $numbers = \array_values(\array_filter(
            \array_map(static fn ($mark): string => $mark->text, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)),
            static fn (string $text): bool => 'A' !== $text,
        ));

        $this->assertSame(['0', '8', '16'], $numbers);
    }

    public function testATextTooWideIsCutShortAsPlantUmlCutsIt(): void
    {
        $drawing = Work::run(self::read('0: A rather long description')->drawing());
        $texts = \array_map(
            static fn ($mark): string => $mark->text,
            \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind && 1 === \preg_match('/\D/', $mark->text))),
        );

        $this->assertSame(['A ...'], $texts);
    }

    public function testAFieldOfNoBitsIsNotDrawn(): void
    {
        $drawing = Work::run(self::read("0-1: B\n2: A [len = 0]")->drawing());
        $texts = \array_map(static fn ($mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)));

        // As the jar draws it: B's box alone, A neither boxed nor written.
        $this->assertSame(['0', 'B'], $texts);
        $this->assertCount(1, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind));
    }

    public function testAScaleOfNoLineColourIsNumberedButNotTicked(): void
    {
        $drawing = Work::run(self::read("<style>\npacketdiagDiagram {\nLineColor none\n}\n</style>\n0-3: A")->drawing());

        $this->assertSame([], \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind)));
        $this->assertContains('0', \array_map(static fn ($mark): string => $mark->text, $drawing->marks));
    }

    public function testHidingIsReadAndChangesNothing(): void
    {
        $this->assertSame(self::read('0-3: A')->fields, self::read("hide empty description\n0-3: A")->fields);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'a command packets do not read' => ['set namespaceSeparator ::', 'does not draw: set namespaceSeparator ::'];
        yield 'a first row of no bits, which PlantUML fails on' => ['0: A [len = 0]', 'first row is no bit wide'];
        yield 'a first field ending before it starts' => ['3-1: A', 'first row is no bit wide'];
    }

    #[DataProvider('refusals')]
    public function testItLeavesToPlantUmlWhatItDoesNotDraw(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startpacketdiag\n".$lines."\n@endpacketdiag"));
    }

    public function testTheModelListsTheFields(): void
    {
        $graph = self::read("0-7: A\n8: B")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['0-7: A', '8: B'], \array_map(static fn (Node $node): ?string => $node->label, $graph->nodes));
    }

    private static function read(string $lines): PlantUmlPacket
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startpacketdiag\n".$lines."\n@endpacketdiag");
        self::assertInstanceOf(PlantUmlPacket::class, $diagram);

        return $diagram;
    }
}
