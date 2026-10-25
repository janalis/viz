<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Style\Style;
use PHPUnit\Framework\TestCase;

final class LinkLabelTest extends TestCase
{
    public function testANoteStandsBelowAboveOrBesideTheLabel(): void
    {
        $style = new Style(['FontSize' => '12']);
        $centres = [];
        foreach (['bottom', 'top', 'left', 'right'] as $position) {
            $label = LinkLabel::of(['label'], $style, [new PlantUmlNote('n', ['note'], position: $position)], $style);
            [$width, $height] = $label->size();
            $at = [];
            foreach ($label->marks(0.0, 0.0) as $mark) {
                if ('' === $mark->text) {
                    continue;
                }

                $at[$mark->text] = $mark->points[0];
            }
            $centres[$position] = $at;
            $this->assertGreaterThan(0.0, $height);
        }

        $this->assertGreaterThan($centres['bottom']['label'][1], $centres['bottom']['note'][1], 'below');
        $this->assertLessThan($centres['top']['label'][1], $centres['top']['note'][1], 'above');
        $this->assertLessThan($centres['left']['label'][0], $centres['left']['note'][0], 'on the left');
        $this->assertGreaterThan($centres['right']['label'][0], $centres['right']['note'][0], 'on the right');
    }

    public function testItsRoomIsTakenEitherSideOfItsTextNotOfItsNote(): void
    {
        $style = new Style(['FontSize' => '12']);
        $plain = LinkLabel::of(['label'], $style, [], $style);
        $noted = LinkLabel::of(['label'], $style, [new PlantUmlNote('n', ['a much longer note'])], $style);
        $noteOnly = LinkLabel::of([], $style, [new PlantUmlNote('n', ['note'])], $style);
        $reach = static function (LinkLabel $label): array {
            $xs = \array_merge(...\array_map(
                static fn (Mark $mark): array => \array_column($mark->points, 0),
                \array_filter($label->marks(0.0, 0.0, 12.0), static fn (Mark $mark): bool => 'none' === $mark->fill),
            ));

            return [\min($xs), \max($xs)];
        };

        // As PlantUML's limit finder is seen to take a loop's label: 6 either side of its text.
        $this->assertEqualsWithDelta([0.0, $plain->size()[0] + 12.0], $reach($plain), 1e-9);
        [$left, $right] = $reach($noted);
        $this->assertEqualsWithDelta($plain->size()[0] + 12.0, $right - $left, 1e-9);
        // As svek writes the box: the room around the text, not the note.
        $this->assertEqualsWithDelta([$plain->size()[0] + 2, $plain->size()[1] + 2], $plain->size(2.0), 1e-9);
        $this->assertSame($noteOnly->size(), $noteOnly->size(2.0));
        $this->assertSame(
            [],
            \array_filter(
                $noteOnly->marks(0.0, 0.0, 12.0),
                static fn (Mark $mark): bool => 'none' === $mark->fill && 'none' === $mark->stroke,
            ),
        );
    }

    public function testALabelWithoutANoteIsItsTextAlone(): void
    {
        $style = new Style([]);
        $label = LinkLabel::of([], $style, [], $style);

        $this->assertTrue($label->empty());
        $this->assertSame([], \array_map(static fn (Mark $mark): string => $mark->text, $label->marks(0.0, 0.0)));
    }
}
