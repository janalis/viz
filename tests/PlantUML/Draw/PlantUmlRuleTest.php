<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use PHPUnit\Framework\TestCase;

final class PlantUmlRuleTest extends TestCase
{
    public function testCreoleRulesTakeTheirOwnRoom(): void
    {
        $line = new PlantUmlText(['a'], 13.0)->size();
        $title = new PlantUmlText(['t'], 13.0)->size();

        // As PlantUML's `CreoleHorizontalLine`: 10 tall bare, as tall as its title with one; `...` a line of text.
        $this->assertEqualsWithDelta([\max($line[0], 10.0), (2 * $line[1]) + 10], new PlantUmlText(['a', '----', 'a'], 13.0)->size(), 1e-9);
        $this->assertEqualsWithDelta([\max($line[0], $title[0]), (2 * $line[1]) + $title[1]], new PlantUmlText(['a', '==t==', 'a'], 13.0)->size(), 1e-9);
        $this->assertEqualsWithDelta([10.0, 20.0], new PlantUmlText(['====', '....'], 13.0)->size(), 1e-9);
        $this->assertEqualsWithDelta(new PlantUmlText(['...'], 13.0)->size(), new PlantUmlText(['...'], 13.0)->size(), 1e-9);
        $this->assertSame(['--a b--'], PlantUmlText::styled(['--a b--'], new Style(['MaximumWidth' => '1']))->lines);
    }

    public function testRulesAreDrawnAcrossTheirWidth(): void
    {
        $marks = new PlantUmlText(['--', '==t==', '..d..'], 13.0, color: '#123456')->marks(0.0, 0.0, 100.0);
        $lines = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));
        $texts = \array_map(static fn (Mark $mark): string => $mark->text, \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind)));

        // As PlantUML draws them: `--` alone a line of text, a doubled line split by its title, a dotted one by its.
        $this->assertSame(['--', 't', 'd'], $texts);
        $this->assertCount(6, $lines);
        $this->assertSame([[1.0, 2.0], [1.0, 2.0]], \array_values(\array_filter(\array_map(static fn (Mark $mark): ?array => $mark->dashes, $lines))));
    }
}
