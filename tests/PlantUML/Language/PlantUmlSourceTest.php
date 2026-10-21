<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Language;

use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlSourceTest extends TestCase
{
    public function testTheBlockIsReadWithCommentsDroppedAndLinesJoined(): void
    {
        $source = PlantUmlSource::parse(<<<'PUML'
            before
              @startuml name
            ' a comment
            Alice -> Bob : hello \
            there
            /' one-line '/
            /' several
            lines '/
            /' leading '/ Bob -> Alice
            Bob -> Carol /' trailing '/
            path \\
            !pragma layout smetana
            !PRAGMA teoz
            @enduml
            after
            PUML);

        $this->assertSame(['Alice -> Bob : hello there', ' Bob -> Alice', 'Bob -> Carol ', 'path \\\\'], $source->lines);
        $this->assertSame(['layout' => 'smetana', 'teoz' => ''], $source->pragmas);
    }

    public function testBackslashStartsAndATrailingJoinAreRead(): void
    {
        $this->assertSame(['a'], PlantUmlSource::parse("\\startuml\r\na\r\n\\enduml")->lines);
        $this->assertSame([], PlantUmlSource::parse("@startuml\n@enduml\\")->lines);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'no block' => ['a -> b', 'The PlantUML source has no @startuml … @enduml block'];
        yield 'unclosed' => ["@startuml\na -> b", 'The PlantUML source has no @startuml … @enduml block'];
        yield 'two blocks' => ["@startuml\n@enduml\n@startuml\n@enduml", 'The PlantUML source has several diagrams'];
        yield 'other kind' => ["@startmindmap\n* a\n@endmindmap", 'The native PlantUML renderer does not draw @startmindmap diagrams'];
        yield 'include' => ["@startuml\n!include foo.puml\n@enduml", 'The native PlantUML renderer leaves !include to PlantUML'];
        yield 'function' => ["@startuml\n%date()\n@enduml", 'The native PlantUML renderer leaves %date() to PlantUML'];
        yield 'a directive the diagram reads' => ["@startuml\n!assume transparent\n@enduml", 'The native PlantUML renderer leaves !assume to PlantUML'];
    }

    #[DataProvider('refused')]
    public function testWhatPlantUmlMustReadItselfIsRefused(string $source, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        PlantUmlSource::parse($source);
    }
}
