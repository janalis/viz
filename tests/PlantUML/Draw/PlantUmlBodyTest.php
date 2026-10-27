<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlBody;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class PlantUmlBodyTest extends TestCase
{
    public function testSeparatorsSplitTheTextIntoSectionsAsPlantUmlSetsThem(): void
    {
        $text = new PlantUmlText(['one', '--', 'two', '==title==', 'three', '...', '....', '__', 'four'], 13.0);
        [$plainWidth, $plainHeight] = new PlantUmlText(['one', 'two', 'three', '...', 'four'], 13.0)->size();
        $title = new PlantUmlText(['title'], 13.0)->size();

        // As PlantUML's `BodyEnhanced2`: 4 above and below each plain section, the title's height above a titled one, 4 below and 6 right; `...` a line of text.
        $this->assertEqualsWithDelta([$plainWidth + 6, $plainHeight + 8 + $title[1] + 4 + 8 + 8], new PlantUmlBody($text)->size(), 1e-9);
    }

    public function testASectionKeepsItsTextsGuillemets(): void
    {
        $texts = static fn (string $guillemet): array => \array_values(\array_map(
            static fn (Mark $mark): string => $mark->text,
            \array_filter(
                Work::run(PlantUmlPipeline::native()->drawing("@startuml\nskinparam guillemet {$guillemet}\nclass A\nnote \"a <<b>> c\" as N\n@enduml"))->marks,
                static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && \str_starts_with($mark->text, 'a '),
            ),
        ));

        // As the jar draws them: between the guillemets set, or as written, its `<b>` then bold.
        $this->assertSame([['a "[b]" c'], ['a <']], [$texts('"[ ]"'), $texts('false')]);
    }

    public function testATitleWiderThanItsSectionWidensIt(): void
    {
        [$titleWidth] = new PlantUmlText(['a much longer title'], 13.0)->size();

        $this->assertEqualsWithDelta($titleWidth + 8, new PlantUmlBody(new PlantUmlText(['a', '--a much longer title--', 'b'], 13.0))->size()[0], 1e-9);
    }

    public function testANotesSeparatorsAreDrawnAcrossIt(): void
    {
        $drawing = Work::run(PlantUmlPipeline::native()->drawing("@startuml\nclass A\nnote right of A\n  first\n  ----\n  second\n  ==title==\n  third\n  ....\n  fourth\nend note\n@enduml"));
        $lines = \array_values(\array_filter(
            $drawing->marks,
            static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && 2 === \count($mark->points) && $mark->points[0][1] === $mark->points[1][1] && $mark->strokeWidth >= Units::POINT,
        ));

        // As the jar draws it, 164 by 127: a line, a doubled line on either side of its title, a dotted line.
        $this->assertSame([164.0, 127.0], [$drawing->width / Units::POINT, $drawing->height / Units::POINT]);
        $this->assertCount(6, $lines);
        $this->assertCount(1, \array_filter($lines, static fn (Mark $mark): bool => $mark->dashed));
    }
}
