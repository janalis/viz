<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\Drawing\DotRecord;
use Janalis\Viz\GraphViz\Drawing\DotText;
use PHPUnit\Framework\TestCase;

final class DotRecordTest extends TestCase
{
    public function testFieldsStandSideBySideAndBracesTurnThem(): void
    {
        $record = self::record('<a> one|{two|<b> three}|');
        $cell = Units::cell(14.0);
        $line = Units::line(14.0);

        $this->assertSame([(3 * $cell) + 16 + (5 * $cell) + 16 + 16, (2 * $line) + 16], $record->size(), 'one, the wider of two and three, an empty field; two lines tall');
        $this->assertSame(['one', 'two', 'three', ''], \array_map(static fn (array $cell): string => $cell[0]->lines[0][0], $record->cells([0.0, 0.0, 100.0, 50.0])));
        $this->assertSame(
            [[[$record->cells([0.0, 0.0, 100.0, 50.0])[1][1][0], 0.0], [$record->cells([0.0, 0.0, 100.0, 50.0])[1][1][0], 50.0]]],
            \array_slice($record->separators([0.0, 0.0, 100.0, 50.0]), offset: 0, length: 1),
            'a line before the second field, from top to bottom',
        );
        [, [, [$left, , $right]]] = $record->cells([0.0, 0.0, 100.0, 50.0]);
        $this->assertSame([$left, 25.0, $right, 25.0], self::flat($record->separators([0.0, 0.0, 100.0, 50.0])[1]), 'within the braces, a line across them');
        $this->assertSame(25.0, $record->port('b', [0.0, 0.0, 100.0, 50.0])[1] ?? null, 'the port b names the lower field in the braces');
        $this->assertNull($record->port('c', [0.0, 0.0, 100.0, 50.0]));
    }

    public function testARecordAcrossStacksItsFieldsAndSharesRoomByTheirSize(): void
    {
        $record = self::record('a|bbb', across: false);
        [$first, $second] = $record->cells([0.0, 0.0, 10.0, 100.0]);

        $this->assertSame([0.0, 0.0, 10.0, 50.0], $first[1], 'one line each: halves');
        $this->assertSame([0.0, 50.0, 10.0, 100.0], $second[1]);
        [$narrow, $wide] = self::record('a|bbb')->cells([0.0, 0.0, 100.0, 10.0]);
        $this->assertGreaterThan($narrow[1][2] - $narrow[1][0], $wide[1][2] - $wide[1][0]);
    }

    public function testEscapesStandForThemselvesAndBadLabelsAreRefused(): void
    {
        $this->assertSame('a{b}|<c> d', self::record('a\{b\}\|\<c\> d')->cells([0.0, 0.0, 1.0, 1.0])[0][0]->lines[0][0]);
        $this->assertSame('a', self::record('a\l')->cells([0.0, 0.0, 1.0, 1.0])[0][0]->lines[0][0], 'a line end is the text\'s own');
        foreach (['{a', 'a}', '{a}b', 'a{b', 'a>b', '<a', '<a><b>c', '{a}}'] as $bad) {
            $this->assertNull(DotRecord::parse($bad, across: true, text: static fn (string $label): DotText => DotText::parse($label, [])), $bad);
        }
        $this->assertNotNull(DotRecord::parse(' { a } | b', across: true, text: static fn (string $label): DotText => DotText::parse($label, [])));
    }

    private static function record(string $label, bool $across = true): DotRecord
    {
        $record = DotRecord::parse($label, $across, static fn (string $text): DotText => DotText::parse($text, []));
        self::assertNotNull($record, $label);

        return $record;
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<float>
     */
    private static function flat(array $points): array
    {
        return \array_merge(...$points);
    }
}
