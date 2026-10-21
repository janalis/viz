<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Grades the native drawing of each fixture in tests/fixtures/plantuml-parity against what
 * PlantUML drew for it, as tools/record-plantuml-parity.php recorded it from the pinned jar:
 * 0 when left to PlantUML, 1 when it writes the same words, 2 when its height is also within
 * 5% of PlantUML's, 3 within 2%, and 4 when every word also stands within 3 points of where
 * PlantUML writes it down the diagram, their baselines aligned on the median offset. Widths are not graded: text is set in the bundled monospace
 * font, wider than PlantUML's. Each fixture's grade may only rise above its floor in manifest.php.
 */
final class PlantUmlParityTest extends TestCase
{
    private const string ROOT = __DIR__.'/../fixtures/plantuml-parity';

    /** @return iterable<string, array{string, int}> */
    public static function fixtures(): iterable
    {
        /** @var array<string, int> $floors */
        $floors = require self::ROOT.'/manifest.php';
        foreach ($floors as $name => $floor) {
            yield $name => [$name, $floor];
        }
    }

    #[DataProvider('fixtures')]
    public function testTheNativeDrawingKeepsItsGrade(string $name, int $floor): void
    {
        $this->assertGreaterThanOrEqual($floor, self::grade($name), $name.' fell below its grade');
    }

    public function testEveryFixtureHasAGoldenAndAFloor(): void
    {
        $fixtures = \array_map(static fn (string $path): string => \substr($path, \strlen(self::ROOT) + 1, -5), self::files('puml'));
        $goldens = \array_map(static fn (string $path): string => \substr($path, \strlen(self::ROOT) + 1, -5), self::files('json'));

        $this->assertSame($fixtures, $goldens);
        $this->assertSame($fixtures, \array_keys(require self::ROOT.'/manifest.php'));
    }

    /** The grade the native drawing of a fixture earns against PlantUML's. */
    public static function grade(string $name): int
    {
        /** @var array{width: float, height: float, words: list<string>, baselines: list<array{string, float}>} $golden */
        $golden = \json_decode((string) \file_get_contents(self::ROOT.'/'.$name.'.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        try {
            $drawing = Work::run(PlantUmlPipeline::native()->drawing((string) \file_get_contents(self::ROOT.'/'.$name.'.puml')));
        } catch (Unsupported) {
            return 0;
        }
        $placed = [];
        foreach ($drawing->marks as $mark) {
            // An image's mark holds its data, not words.
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            foreach ((array) \preg_split('/\s+/u', \trim((string) $mark->text), flags: \PREG_SPLIT_NO_EMPTY) as $word) {
                $placed[] = [\str_replace(['«', '»'], ['<<', '>>'], (string) $word), $mark->points[0][1] / Units::POINT];
            }
        }
        $expected = \array_map(static fn (array $word): array => [\str_replace(['«', '»'], ['<<', '>>'], $word[0]), $word[1]], $golden['baselines']);
        $placed = self::withoutGlyphs($placed, $expected);
        \sort($placed);
        \sort($expected);
        if (\array_column($placed, 0) !== \array_column($expected, 0)) {
            return 0;
        }
        $off = \abs(($drawing->height / Units::POINT) - $golden['height']) / $golden['height'];

        return match (true) {
            $off <= 0.02 && self::rowsAgree($placed, $expected) => 4,
            $off <= 0.02 => 3,
            $off <= 0.05 => 2,
            default => 1,
        };
    }

    /** @return list<string> */
    private static function files(string $extension): array
    {
        $files = \glob(self::ROOT.'/*/*.'.$extension);

        return false === $files ? [] : $files;
    }

    /**
     * The words without the single letters PlantUML draws as outlines rather than text — those
     * in a class's or a connector's circle — keeping, of each letter, the ones nearest where
     * PlantUML writes it as text.
     *
     * @param list<array{string, float}> $placed
     * @param list<array{string, float}> $expected
     * @return list<array{string, float}>
     */
    private static function withoutGlyphs(array $placed, array $expected): array
    {
        $kept = \array_values(\array_filter($placed, static fn (array $word): bool => 1 !== \mb_strlen($word[0])));
        $letters = [];
        foreach ($placed as $word) {
            if (1 !== \mb_strlen($word[0])) {
                continue;
            }
            $letters[$word[0]][] = $word[1];
        }
        foreach ($expected as [$letter, $y]) {
            if (1 !== \mb_strlen($letter) || [] === ($letters[$letter] ?? [])) {
                continue;
            }
            $distances = \array_map(static fn (float $candidate): float => \abs($candidate - $y), $letters[$letter]);
            $nearest = (int) \array_search(\min($distances), $distances, strict: true);
            $kept[] = [$letter, $letters[$letter][$nearest]];
            \array_splice($letters[$letter], $nearest, length: 1);
        }

        return $kept;
    }

    /**
     * Whether each word stands within 3 points of PlantUML's, once both are shifted by their median offset.
     *
     * @param list<array{string, float}> $placed
     * @param list<array{string, float}> $expected
     */
    private static function rowsAgree(array $placed, array $expected): bool
    {
        $offsets = [];
        foreach ($placed as $index => $word) {
            $offsets[] = $word[1] - $expected[$index][1];
        }
        \sort($offsets);
        $median = $offsets[\intdiv(\count($offsets), num2: 2)] ?? 0.0;

        return [] === \array_filter($offsets, static fn (float $offset): bool => \abs($offset - $median) > 3.0);
    }
}
