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
            foreach ($label->marks(0.0, 0.0, $width) as $mark) {
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

    public function testALabelWithoutANoteIsItsTextAlone(): void
    {
        $style = new Style([]);
        $label = LinkLabel::of([], $style, [], $style);

        $this->assertTrue($label->empty());
        $this->assertSame([], \array_map(static fn (Mark $mark): string => $mark->text, $label->marks(0.0, 0.0, 0.0)));
    }
}
