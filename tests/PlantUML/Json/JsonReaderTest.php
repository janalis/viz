<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Json;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Json\JsonHighlight;
use Janalis\Viz\PlantUML\Json\JsonKind;
use Janalis\Viz\PlantUML\Json\JsonNode;
use Janalis\Viz\PlantUML\Json\JsonParser;
use Janalis\Viz\PlantUML\Json\PlantUmlJson;
use Janalis\Viz\PlantUML\Json\YamlParser;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonReaderTest extends TestCase
{
    public function testJsonKeepsOrderDuplicatesNumbersAsWrittenAndComments(): void
    {
        $node = JsonParser::parse("{\n \"b\": 1.50, // one\n \"a\": [true, null, -2e3],\n \"b\": \"x\\ty\\u00e9\"\n}");

        $this->assertSame(['b', 'a', 'b'], \array_column($node->members, 0));
        $this->assertSame(['1.50', "x\ty\u{e9}"], [$node->members[0][1]->text, $node->members[2][1]->text]);
        $this->assertSame(['0', '1', '2'], \array_column($node->members[1][1]->members, 0));
        $this->assertSame(["\u{2611} true", "\u{2400}", '-2e3'], \array_map(static fn (array $member): string => $member[1]->short(), $node->members[1][1]->members));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badJson(): iterable
    {
        yield 'trailing' => ['{} x', 'Unexpected character'];
        yield 'unquoted name' => ['{a: 1}', 'Expected name'];
        yield 'leading zero' => ['[01]', "Expected ']'"];
        yield 'bare fraction' => ['[1.]', 'Expected digit'];
        yield 'bad escape' => ['["\\x"]', 'Expected valid escape sequence'];
        yield 'unclosed' => ['{"a": 1', 'Unexpected end of input'];
        yield 'single slash' => ['{"a": 1 /}', 'Error'];
    }

    #[DataProvider('badJson')]
    public function testJsonFailsWherePlantUmlsParserFails(string $json, string $message): void
    {
        $this->expectException(PlantUmlRefusal::class);
        $this->expectExceptionMessage(\str_replace('Error', replace: "Expected '/'", subject: $message));

        JsonParser::parse($json);
    }

    public function testYamlReadsMapsListsAndScalarsAsPlantUmlDoes(): void
    {
        $node = YamlParser::parse([
            'name: demo # a comment',
            "quoted: 'a: b'",
            'tags: [one, "two, three", four]',
            'list:',
            '  - a',
            '  - key: value',
            '    other: 2',
            '  -',
            '    alone: x',
            'text: |',
            '  first',
            '  second',
            'folded:',
            '  some text',
            '',
            '  on lines',
            'empty:',
            'map:',
            "\tdeep: 1",
            'name: again',
        ]);

        $this->assertSame(['name', 'quoted', 'tags', 'list', 'text', 'folded', 'empty', 'map'], \array_column($node->members, 0));
        $values = \array_column($node->members, 1, 0);
        $this->assertSame(['again', 'a: b', "first\nsecond\n", 'some text on lines', ''], [
            $values['name']->text,
            $values['quoted']->text,
            $values['text']->text,
            $values['folded']->text,
            $values['empty']->text,
        ]);
        $this->assertSame(['one', 'two, three', 'four'], \array_map(static fn (array $member): string => $member[1]->text, $values['tags']->members));
        $list = \array_column($values['list']->members, 1);
        $this->assertSame([JsonKind::String, JsonKind::Object, JsonKind::Object], \array_map(static fn (JsonNode $item): JsonKind => $item->kind, $list));
        $this->assertSame([['key', 'other'], ['alone']], [\array_column($list[1]->members, 0), \array_column($list[2]->members, 0)]);
        $this->assertSame('1', $values['map']->members[0][1]->text);
    }

    public function testATopLevelYamlListIsAnArray(): void
    {
        $node = YamlParser::parse(['- name: a', '  hr: 1', '- name: b']);

        $this->assertSame(JsonKind::Array, $node->kind);
        $this->assertSame([['name', 'hr'], ['name']], \array_map(static fn (array $member): array => \array_column($member[1]->members, 0), $node->members));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badYaml(): iterable
    {
        yield 'text alone' => [['just text']];
        yield 'folded' => [['a: >', '  b']];
        yield 'nothing' => [['# only a comment']];
        yield 'an item in a map' => [['a: 1', '- b']];
    }

    /** @param list<string> $lines */
    #[DataProvider('badYaml')]
    public function testYamlFailsWherePlantUmlsParserFails(array $lines): void
    {
        $this->expectException(PlantUmlRefusal::class);

        YamlParser::parse($lines);
    }

    public function testHighlightsReachThroughPaths(): void
    {
        $highlight = JsonHighlight::of('#highlight "a" / * / "c" <<blue>>');

        $this->assertNotNull($highlight);
        $this->assertSame(['a', '*', 'c'], $highlight->path);
        $this->assertSame(['blue'], $highlight->stereotypes());
        $this->assertNull($highlight->under('b'));
        $this->assertTrue($highlight->under('a')?->under('anything')?->highlights('c'));
        $anywhere = JsonHighlight::of('#highlight ** / "x"');
        $this->assertTrue($anywhere?->under('p')?->under('q')?->highlights('x'));
        $this->assertNull(JsonHighlight::of('#highlightx'));
    }

    public function testTheSourceSplitsAsPlantUmlsStyleExtractorSplitsIt(): void
    {
        $diagram = self::read('json', <<<'PUML'
            title Data
            skinparam monochrome true
            hide empty
            <style>
            jsonDiagram { node { BackGroundColor pink } }
            </style>
            # a line JSON leaves out
            #highlight "a"
            {"a": 1, "title x": {"b": []}}
            PUML);

        $this->assertSame(['Data'], $diagram->document->captions['title']->lines ?? null);
        $this->assertCount(1, $diagram->highlights);
        $this->assertSame(['a', 'title x'], \array_column($diagram->root->members, 0));
        $this->assertSame('#ffc0cb', $diagram->document->styles->style(['root', 'element', 'jsonDiagram', 'node'])->color('BackGroundColor'));
    }

    public function testALoneValueOrAnEmptyContainerStandsInAnArray(): void
    {
        $this->assertSame([JsonKind::Array, 'x'], [self::read('json', '"x"')->root->kind, self::read('json', '"x"')->root->members[0][1]->text]);
        $this->assertSame('', self::read('json', '[]')->root->members[0][1]->text);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusals(): iterable
    {
        yield 'bad json' => ['json', '{', 'PlantUML does not read this JSON data: Unexpected end of input'];
        yield 'bad yaml' => ['yaml', 'text', 'PlantUML does not read this YAML data'];
        yield 'handwritten' => ['json', "skinparam handwritten true\n{}", 'handwritten'];
        yield 'scaled to a size' => ['json', "scale 200 width\n{}", 'scaling to a size'];
        yield 'another skin' => ['yaml', "skin debug\na: 1", 'does not have the debug skin'];
    }

    #[DataProvider('refusals')]
    public function testItLeavesToPlantUmlWhatItDoesNotRead(string $kind, string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        self::read($kind, $lines);
    }

    public function testTheModelJoinsEachContainerToTheTablesItHolds(): void
    {
        $graph = self::read('yaml', "title T\na: 1\nb:\n  c: [x, y]")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['T', Direction::LeftRight], [$graph->title, $graph->direction]);
        $this->assertSame([['n0', "a: 1\nb:"], ['n1', 'c:'], ['n2', "x\ny"]], \array_map(static fn (Node $node): array => [$node->id, $node->label], $graph->nodes));
        $this->assertSame([['n0', 'n1', 'b'], ['n1', 'n2', 'c']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->label], $graph->edges));
    }

    public function testEachContainerHangsFromItsRowByADashedCurve(): void
    {
        $drawing = Work::run(self::read('json', '{"a": 1, "b": {"c": 2}}')->drawing());
        $texts = [];
        foreach ($drawing->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $texts[$mark->text] = [$mark->points[0][0] / Units::POINT, $mark->points[0][1] / Units::POINT];
        }
        $curves = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind && $mark->dashed));

        $this->assertGreaterThan($texts['b'][0], $texts['c'][0]);
        $this->assertCount(1, $curves);
        $this->assertSame([3.0, 3.0], \array_map(static fn (float $length): float => \round($length / Units::POINT, precision: 6), $curves[0]->dashes ?? []));
        // It starts level with its row, 13 back from the table's side.
        $this->assertEqualsWithDelta($texts['b'][1], $curves[0]->points[0][1] / Units::POINT, 5.0);
    }

    private static function read(string $kind, string $lines): PlantUmlJson
    {
        $diagram = PlantUmlPipeline::native()->diagram('@start'.$kind."\n".$lines."\n@end".$kind);
        self::assertInstanceOf(PlantUmlJson::class, $diagram);

        return $diagram;
    }
}
