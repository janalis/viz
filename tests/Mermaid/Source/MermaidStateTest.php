<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\Source\MermaidStateReader;
use Janalis\Viz\Mermaid\Source\MermaidStateWriter;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\NoteSide;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Tests\Support\StateSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidStateTest extends TestCase
{
    public function testAStateDiagramIsReadIntoTheSharedModel(): void
    {
        $diagram = MermaidStateReader::read(<<<'MMD'
            ---
            title: Lights
            ---
            stateDiagram-v2
              direction LR
              [*] --> Off
              Off --> On : press
              state On {
                direction TB
                [*] --> Dim
                --
                [*] --> Warm
              }
              state pick <<choice>>
              On --> pick
              pick --> [*]
              Off : no light
              note left of On : lit
              note "free" as N
            MMD);

        $this->assertSame([Direction::LeftRight, 'Lights'], [$diagram->direction, $diagram->title]);
        $this->assertSame(['@start:', 'Off', 'On', 'pick', '@end:'], \array_map(static fn ($state): string => $state->id, $diagram->states));
        $this->assertSame(['no light'], $diagram->states[1]->description);
        $on = $diagram->states[2];
        $this->assertSame([Direction::TopBottom, 2], [$on->direction, \count($on->regions)]);
        $this->assertSame(['@start:On', 'Dim'], \array_map(static fn ($state): string => $state->id, $on->regions[0]->states));
        $this->assertSame([StateKind::Initial, StateKind::Choice, StateKind::Final], [$diagram->states[0]->kind, $diagram->states[3]->kind, $diagram->states[4]->kind]);
        $this->assertSame(['Off>On:press', '@start:>Off:'], [
            $diagram->transitions[1]->from.'>'.$diagram->transitions[1]->to.':'.$diagram->transitions[1]->label,
            $diagram->transitions[0]->from.'>'.$diagram->transitions[0]->to.':'.$diagram->transitions[0]->label,
        ]);
        $this->assertSame(['lit', 'On', NoteSide::Left], [$diagram->notes[0]->text, $diagram->notes[0]->target, $diagram->notes[0]->side]);
        $this->assertSame([null, NoteSide::Right], [$diagram->notes[1]->target, $diagram->notes[1]->side]);
    }

    public function testForksJoinsAndTheDispatcherReadTheSameWay(): void
    {
        $diagram = new MermaidReader()->read("stateDiagram\n  state f <<fork>>\n  state j <<join>>\n  f --> j");

        $this->assertInstanceOf(StateDiagram::class, $diagram);
        $this->assertSame([StateKind::Fork, StateKind::Join], [$diagram->states[0]->kind, $diagram->states[1]->kind]);
    }

    public function testTheSharedModelIsWrittenAsAStateDiagram(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Machine"
            ---
            stateDiagram-v2
                direction LR
                Idle
                Idle : waiting
                Idle : two
                Idle : lines
                state "Busy #quot;now#quot;" as Busy {
                    Load
                    history
                    [*] --> Load
                    --
                    Spin
                    deep
                    Spin --> deep
                    Load --> Spin : across
                }
                state Paused {
                    direction LR
                    Frozen
                    [*] --> Frozen
                }
                state pick <<choice>>
                state split <<fork>>
                state merge <<join>>
                state "state" as s1
                [*] --> Idle
                Idle --> Busy : go
                Busy --> pick : two<br>lines
                pick --> [*]
                note left of Idle : beside
                note right of Busy : several<br>lines
                note "floating #quot;free#quot;" as note2

            MMD, MermaidStateWriter::write(StateSample::diagram()));
    }

    #[DataProvider('corpus')]
    public function testWritingWhatWasReadReachesAFixedPoint(string $file): void
    {
        $first = MermaidStateWriter::write(MermaidStateReader::read((string) \file_get_contents($file)));

        $this->assertSame($first, MermaidStateWriter::write(MermaidStateReader::read($first)));
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        $files = \glob(__DIR__.'/../../fixtures/mermaid/{state,upstream/state-diagram,upstream/state-diagram-v2}/*.mmd', \GLOB_BRACE);
        foreach (false === $files ? [] : $files as $file) {
            if (!\str_contains((string) \file_get_contents($file), 'stateDiagram') || \str_contains($file, 'unknown')) {
                continue;
            }
            yield \basename(\dirname($file)).'/'.\basename($file) => [$file];
        }
    }
}
