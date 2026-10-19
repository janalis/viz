<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Language;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use PHPUnit\Framework\TestCase;

final class PlantUmlCommandsTest extends TestCase
{
    public function testLinesMatchCommandsInPlantUmlsOrder(): void
    {
        $commands = PlantUmlCommands::family('class')->match(['class A {', '  +x : int', '}', '', 'package P', '{', '}', 'A : y()']);

        $this->assertSame(
            ['CommandCreateClassMultilines', 'CommandNope', 'CommandPackage', 'CommandEndPackage', 'CommandAddMethod'],
            \array_map(static fn (PlantUmlCommand $command): string => $command->name, $commands ?? []),
        );
        $this->assertSame(['  +x : int'], $commands[0]->body());
        $this->assertSame(['package P', '{'], $commands[2]->lines);
        $this->assertSame('A', $commands[4]->get('NAME'));
        $this->assertSame('y()', $commands[4]->get('DATA'));
        $this->assertNull($commands[4]->get('NAME', 3));
        $this->assertSame('package P', $commands[2]->line());
    }

    public function testAlternativeGroupsReadLazily(): void
    {
        $command = PlantUmlCommands::family('class')->match(['class "Long name" as L'])[0] ?? null;

        $this->assertInstanceOf(PlantUmlCommand::class, $command);
        $this->assertSame('L', $command->lazy('CODE'));
        $this->assertSame('Long name', $command->lazy('DISPLAY'));
        $this->assertNull($command->lazy('NOTHING'));
        $this->assertNull($command->lazy('DISPLAY', 1));
    }

    public function testLinesNoCommandTakesAreRefusedAndOpenBlocksPassedOver(): void
    {
        $this->assertNull(PlantUmlCommands::family('class')->match(['Alice -> Bob : hi', 'activate Bob']));
        $this->assertNull(PlantUmlCommands::family('class')->match(['class A {', '  x']));
        $this->assertSame([], PlantUmlCommands::family('class')->match([]));
        $this->assertNull(PlantUmlCommands::family('nothing')->match(['x']));
    }
}
