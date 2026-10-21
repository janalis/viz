<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Json;

use Janalis\Viz\PlantUML\Json\HclParser;
use Janalis\Viz\PlantUML\Json\JsonKind;
use Janalis\Viz\PlantUML\Json\JsonNode;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class HclParserTest extends TestCase
{
    public function testALoneBlockIsTheWholeAndSeveralGoUnderTheirNames(): void
    {
        $one = HclParser::parse(['resource "aws" "web" {', '  ami = "x"', '}']);
        $two = HclParser::parse(['a {', 'x = "1"', '}', 'b "c" {', 'y = "2"', '}']);

        $this->assertSame([['ami', 'x']], self::flat($one));
        $this->assertSame(['a', 'b "c"'], \array_column($two->members, 0));
    }

    public function testFunctionsHoldTheirArgumentsAndArraysOnlyQuotedStringsAndBlocks(): void
    {
        $node = HclParser::parse(['l {', 'j = join(",", ["x", y, "z"])', 'n = now()', '}']);
        [[, $join], [, $now]] = $node->members;

        $this->assertSame('join()', $join->members[0][0]);
        $this->assertSame(
            [',', ['x', 'z']],
            \array_map(static fn (array $member): string|array => JsonKind::Array === $member[1]->kind
                ? \array_column(\array_column($member[1]->members, 1), 'text')
                : $member[1]->text, $join->members[0][1]->members),
        );
        $this->assertSame([JsonKind::String, 'now()'], [$now->kind, $now->text]);
    }

    public function testItsLinesRunTogetherAsPlantUmlRunsThem(): void
    {
        // `2` and `name` meet with nothing between them, one word, and the `=` after it fails, as in PlantUML.
        $this->expectException(PlantUmlRefusal::class);
        $this->expectExceptionMessage('Unexpected =');

        HclParser::parse(['b {', 'count = 2', 'name = "x"', '}']);
    }

    public function testDataPlantUmlCannotReadIsRefused(): void
    {
        $this->expectException(PlantUmlRefusal::class);

        HclParser::parse(['b {', 'x = "1"']);
    }

    public function testTheDiagramSaysSo(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('PlantUML does not read this HCL data');

        Work::run(PlantUmlPipeline::native()->drawing("@starthcl\n= {\n@endhcl"));
    }

    /** @return list<array{string, string}> */
    private static function flat(JsonNode $node): array
    {
        return \array_map(static fn (array $member): array => [$member[0], $member[1]->text], $node->members);
    }
}
