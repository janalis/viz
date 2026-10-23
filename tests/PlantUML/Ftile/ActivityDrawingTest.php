<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Ftile;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityDrawingTest extends TestCase
{
    /** The frame's margin around the diagram, in points. */
    private const float MARGIN = 10.0;

    public function testAStartAndAStopStandAsPlantUmlSetsThem(): void
    {
        $drawing = self::draw("start\nstop");
        $marks = self::points($drawing);
        // As the jar: the start's centre at 19, 15 from the frame, the stop's 41 below, the arrow 20 long between them.
        $this->assertEqualsWithDelta([19.0, 15.0], self::centre($marks[0]), 1e-9);
        $this->assertEqualsWithDelta([19.0, 56.0], self::centre($marks[1]), 1e-9);
        $line = \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind))[0];
        $this->assertEqualsWithDelta([[19.0, 25.0], [19.0, 45.0]], $line->points, 1e-9);
        $this->assertEqualsWithDelta(63.0, $drawing->width / Units::POINT, 1e-9);
        $this->assertEqualsWithDelta(96.0, $drawing->height / Units::POINT, 1e-9);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function diagrams(): iterable
    {
        yield 'actions' => [":Hello world;\n:This is defined on\nseveral **lines**;", ['Hello world', 'several lines']];
        yield 'a decision' => [
            "start\nif (Graphviz installed?) then (yes)\n  :process all\\ndiagrams;\nelse (no)\n  :process only\n  __sequence__ diagrams;\nendif\nstop",
            ['Graphviz installed?', 'yes', 'no', 'diagrams', 'sequence diagrams'],
        ];
        yield 'elseifs' => [
            "start\nif (condition A) then (yes)\n  :Text 1;\nelseif (condition B) then (yes)\n  :Text 2;\n  stop\n(no) elseif (condition C) then (yes)\n  :Text 3;\n  -> out;\nelse (nothing)\n  :Text else;\n  -> gone;\nendif\n-[#red]->\nstop",
            ['condition A', 'condition C', 'no', 'out', 'gone', 'Text else'],
        ];
        yield 'elseifs none leaving' => ["if (a) then\n  stop\nelseif (b) then\n  stop\nelse\n  :c;\nendif", ['a', 'b', 'c']];
        yield 'elseifs none leaving at all' => ["if (a) then\n  stop\nelseif (b) then\n  stop\nelse\n  stop\nendif", ['a', 'b']];
        yield 'a decision without else' => ["start\nif (condition?) then\n  :action;\nendif\nstop", ['condition?', 'action']];
        yield 'a decision whose else stops' => ["start\nif (condition?) then (yes)\n  :action;\nelse (no)\n  stop\nendif\n:after;\nstop", ['yes', 'no', 'after']];
        yield 'a decision whose then stops' => ["start\nif (condition?) then (yes)\n  stop\nelse (no)\n  :action;\nendif\nstop", ['yes', 'no', 'action']];
        yield 'a decision with an empty then' => ["start\nif (condition?) then (yes)\nelse (no)\n  :action;\nendif\nstop", ['condition?', 'action']];
        yield 'a decision with an empty then and an else stopping' => ["if (c?) then (yes)\nelse (no)\n  stop\nendif\n:x;", ['c?', 'x']];
        yield 'a decision whose stopping then is alone' => ["if (c?) then (yes)\n  stop\nendif\n:x;", ['c?', 'x']];
        yield 'a decision with a branch that never leaves' => ["if (c?) then\n  :a;\n  kill\nelse\n  :b;\nendif\n:x;", ['a', 'b', 'x']];
        yield 'a decision whose else leaves alone' => ["if (c?) then\n  :a;\n  detach\nelse\n  :b;\n  -[#blue]->\nendif\n:x;", ['a', 'b']];
        yield 'a decision with no branch leaving' => ["if (c?) then\n  :a;\n  detach\nelse\n  :b;\n  detach\nendif", ['a', 'b']];
        yield 'a decision with long labels' => [
            "if (c?) then (a very long label for the first branch)\n  :a;\nelse (a very long label\\non two lines\\nand three)\n  :b;\nendif",
            ['a very long label for the first branch', 'and three'],
        ];
        yield 'a decision with notes' => ["if (c?) then\n  :a;\nelse\n  :b;\nendif\nnote left: a long note on the left\nnote right: right\nnote right: again", ['a long note on the left', 'right']];
        yield 'a decision down with a note' => ["if (c?) then\n  :a;\nendif\nnote left: a long note on the left", ['a long note on the left']];
        yield 'a decision with colours' => ["if (c?) then (yes)\n  -[#blue]->\n  :a;\nelse (no)\n  :b;\n  -[#green]-> out;\nendif <<#pink>>\n-[#gray,bold]->\n:c;", ['out', 'c']];
        yield 'a decision coloured' => ["if (c?) then (yes) <<#pink>>\n  :a;\nelse\n  :b;\nendif", ['c?']];
        yield 'nested decisions' => ["start\nif (a) then\n  :b;\nelse\n  :c;\n  if (d) then\n    :e;\n  endif\nendif\nstop", ['a', 'b', 'c', 'd', 'e']];
        yield 'a repeat' => ["start\nrepeat\n  :read data;\nrepeat while (more data?) is (yes) not (no)\nstop", ['read data', 'more data?', 'yes', 'no']];
        yield 'a repeat with a start label' => ["start\nrepeat :foo as starting label;\n  :read data;\n-> back;\nrepeat while (more data?) is (yes)\n->no;\nstop", ['foo as starting label', 'no']];
        yield 'a repeat backward' => [
            "start\nrepeat\n  :read data;\nbackward:This is backward;\nnote right: on the way back\nrepeat while (more data?)\nstop",
            ['This is backward', 'on the way back'],
        ];
        yield 'a repeat backward labelled' => ["repeat\n  :a;\nbackward:back;\n-> again;\nrepeat while (more?)\n:b;", ['back', 'more?']];
        yield 'a repeat ending the diagram' => ["start\nrepeat\n  :a;\nrepeat while", ['a']];
        yield 'a repeat with breaks' => [
            "start\nrepeat\n  :Test something;\n  if (wrong?) then (no)\n    :OK;\n    break\n  endif\n  if (worse?) then (no)\n    break\n  endif\n  :Alert;\nrepeat while (again?) is (yes) not (no)\n:Alert success;\nstop",
            ['Test something', 'Alert success'],
        ];
        yield 'a repeat killed' => ["repeat\n  :a;\nrepeat while (b?)\nkill", ['a']];
        yield 'a repeat with an arrow in' => ["repeat\n  -> into;\n  :a;\nrepeat while (b?)", ['into']];
        yield 'a repeat in a decision' => ["if (x) then\n  repeat\n    :a;\n  repeat while (b?)\nelse\n  :c;\nendif", ['a', 'c']];
        yield 'a while' => ["start\nwhile (data available?)\n  :read data;\nendwhile\nstop", ['data available?', 'read data']];
        yield 'a while labelled' => ["while (check filesize ?) is (not empty)\n  :read file;\n  -> back;\nendwhile (empty)\n:close file;", ['not empty', 'empty', 'back']];
        yield 'a while backward' => ["start\nwhile (data available?)\n  :read data;\nbackward:This is backward;\nendwhile\nstop", ['This is backward']];
        yield 'a while backward labelled' => ["while (a?)\n  :b;\n-> d;\nbackward:c;\n-> e;\nendwhile\n:f;", ['c', 'f']];
        yield 'a while empty' => ["while (a?)\nendwhile\n:b;", ['a?', 'b']];
        yield 'a while with a break' => ["start\nwhile (Big loop?)\n  :a;\n  if (exit?) then (yes)\n    break\n  endif\n  :b;\nendwhile\n:after;\nstop", ['Big loop?', 'after']];
        yield 'a while killed' => ["while (a?)\n  :b;\nendwhile\nkill", ['b']];
        yield 'a while without a way back' => ["while (a?)\n  :b;\n  detach\nendwhile\n:c;", ['b', 'c']];
        yield 'a while with a note' => ["while (a?)\n  :b;\nendwhile\nnote right: n", ['n']];
        yield 'a while closed by a stop' => ["start\nwhile (a?)\n  :b;\nendwhile\nstop", ['b']];
        yield 'a fork' => ["start\nfork\n  :action 1;\nfork again\n  -> in;\n  :action 2;\n  -> out;\nend fork {and}\nstop", ['action 1', 'action 2', 'in', 'out', '{and}']];
        yield 'a fork coloured' => ["fork <<#red>>\n  :a;\nfork again\n  :b;\n  detach\nend fork\nnote right: n\nnote left: o", ['a', 'b', 'n', 'o']];
        yield 'a fork with a note' => ["fork\n  :a;\nfork again\n  :b;\nend fork\nnote right: n", ['n']];
        yield 'a fork merged' => [
            "start\nfork\n  :action 1;\nfork again\n  :action 2;\nfork again\n  :a much wider action;\nfork again\n  :x;\n  detach\nend merge\nstop",
            ['action 1', 'a much wider action'],
        ];
        yield 'a fork merged into the middle' => ["fork\n  :a;\nend merge\n:b;", ['a', 'b']];
        yield 'a split' => ["start\nsplit\n   :A;\nsplit again\n   :B;\nsplit again\n   -[hidden]->\n   :C;\n   detach\nend split\n:D;\nstop", ['A', 'B', 'C', 'D']];
        yield 'a split hidden' => ["split\n   -[hidden]->\n   :A;\nsplit again\n   -[hidden]->\n   :B;\nend split", ['A', 'B']];
        yield 'a split never leaving' => ["split\n   :A;\n   kill\nsplit again\n   :B;\n   kill\nend split", ['A', 'B']];
        yield 'notes' => [
            "start\n:foo1;\nfloating note left: This is a note\n:foo2;\nnote right\n  This note is on several\n  //lines// and can\n  contain <b>HTML</b>\nend note\nnote left #pink: another\nstop",
            ['This is a note', 'another'],
        ];
        yield 'a note alone' => ["note right: first\n:a;", ['first', 'a']];
        yield 'a floating note alone' => ["floating note right: first\n:a;", ['first', 'a']];
        yield 'partitions' => ["start\npartition Initialization {\n    :read config file;\n}\npartition \"\" {\n    :x;\n}\nstop", ['Initialization', 'read config file']];
        yield 'groups' => [
            "group Group {\n  :Activity;\n}\ncard Card {\n  :Activity;\n}\nrectangle Rect {\n  :Activity;\n  note right: n\n}\npartition P #pink {\n  :x;\n  note left: m\n}",
            ['Group', 'Card', 'Rect', 'n', 'm'],
        ];
        yield 'a group drawing past its width' => [
            "partition a {\n  :b;\n  -> a very long label that reaches far beyond the action;\n  :c;\n}",
            ['a very long label that reaches far beyond the action'],
        ];
        yield 'arrow labels' => [
            ":foo1;\n-> You can put text on arrows;\n:foo2;\n-[#green,dashed]-> The text can\n  also be on several lines;\n:foo3;\n-[#black,dotted]->\n:foo4;\n-[#gray,bold]->\n:foo5;",
            ['You can put text on arrows', 'also be on several lines'],
        ];
        yield 'connectors' => ["start\n:Some activity;\n(A)\ndetach\n(A)\n:Other activity;\n(B)\nkill", ['A', 'B', 'Other activity']];
        yield 'box styles' => [
            "start\n:input; <<input>>\n:output; <<output>>\n:procedure; <<procedure>>\n:load; <<load>>\n:save; <<save>>\n:continuous; <<continuous>>\n:task; <<task>>\n:object; <<object>>\n:objectsignal; <<objectsignal>>\n:acceptevent; <<acceptevent>>\n:timeevent; <<timeevent>>\nend",
            ['procedure', 'timeevent'],
        ];
        yield 'colours' => ["start <<#blue>>\n:colored; <<#pink>>\n:grey; <<#AAAAAA>>\nstop <<#green>>", ['colored', 'grey']];
        yield 'aligned right' => ["<style>\nactivity {\n  HorizontalAlignment right\n}\n</style>\n:a;\n:a longer one;", ['a', 'a longer one']];
        yield 'aligned centre' => ["<style>\nactivity {\n  HorizontalAlignment center\n}\n</style>\n:a;\n:a longer one;", ['a', 'a longer one']];
        yield 'a label after a loop' => ["while (a?)\n  :b;\nendwhile\n-> after;\n:c;\nhide footbox", ['after', 'c']];
        yield 'a group with a note' => ["partition p {\n  note right: n\n  :a;\n}\npartition q {\n  note left: m\n  :b;\n  -[#red]->\n  :c;\n  kill\n}", ['n', 'm']];
        yield 'a while with notes' => ["while (a?)\n  :b;\nendwhile\nnote right: n\nnote left: m", ['n', 'm']];
        yield 'a while with a decision' => ["while (a?)\n  if (b) then\n    :c;\n  endif\n  (A)\n  fork\n    :d;\n  end fork\n  partition p {\n    :e;\n  }\n  end\nendwhile\nstop", ['c', 'd', 'e']];
        yield 'a while breaking in a partition' => ["while (a?)\n  partition p {\n    :x;\n    break\n  }\nendwhile\nstop", ['x']];
        yield 'loops breaking from decisions' => [
            "while (a?)\n  if (b) then\n    :x;\n    break\n  else\n    :y;\n  endif\n  if (c) then\n    :z;\n  else\n    :w;\n    break\n  endif\n  if (d) then\n    :u;\n    break\n  elseif (e) then\n    :v;\n  else\n    :t;\n    break\n  endif\nendwhile\n:after;",
            ['x', 'w', 'u', 'after'],
        ];
        yield 'a decision with two branches leaving none' => ["if (a) then\n  :x;\n  break\nelse\n  :y;\n  break\nendif", ['x', 'y']];
        yield 'elseifs never leaving' => ["if (a) then\n  :x;\n  detach\nelseif (b) then\n  :y;\n  detach\nelse\n  :z;\n  detach\nendif\n:w;", ['x', 'y', 'z', 'w']];
        yield 'a loop breaking from a decision before a stop' => ["while (a?)\n  if (b) then\n    break\n  endif\nendwhile\nstop", ['a?']];
        yield 'a test left empty' => ["if () then\n  :a;\nelse\n  :b;\nendif", ['a', 'b']];
        yield 'a repeat killed early' => ["repeat\n  :a;\n  note right: n\n  kill\nrepeat while (b?)", ['a', 'n']];
        yield 'colours of lines and text' => [':a; <<##red>> <<###blue>>', ['a']];
        yield 'decisions closed by a line' => [
            "skinparam conditionEndStyle HLINE\nif (a) then\n  :b;\nelse\n  :c;\nendif\nif (d) then\n  :e;\nendif\nwhile (f?)\n  if (g) then\n    :h;\n    break\n  else\n    :i;\n  endif\nendwhile\nif (j) then\n  :k;\n  detach\nendif\n:l;",
            ['b', 'c', 'e', 'h', 'i', 'k', 'l'],
        ];
        yield 'else-ifs down' => ["!pragma useVerticalIf on\nif (a) then\n  :b;\n(into c) elseif (c) then (yes)\n  :d;\nendif", ['b', 'into c', 'yes', 'd']];
        yield 'else-ifs down breaking a loop' => [
            "!pragma useVerticalIf on\nwhile (w?)\n  if (a) then\n    :b;\n    break\n  elseif (c) then\n    :d;\n  else\n    :e;\n    break\n  endif\nendwhile\n:after;",
            ['b', 'd', 'e', 'after'],
        ];
        yield 'else-ifs down never leaving' => ["!pragma useVerticalIf true\n:x;\nif (a) then\n  stop\nelseif (c) then\n  stop\nelse\n  stop\nendif", ['a', 'c']];
        yield 'arrows of several colours' => [":a;\n-[#red;#blue]-> two;\n:b;\n-[#red;#blue,hidden]->\n:c;", ['two', 'c']];
        yield 'an arrow of several colours turning' => ["if (a) then\n  :x;\n  -[#red;#blue]-> out;\nelse\n  :y;\nendif", ['out']];
        yield 'arrows of several colours apart' => ["skinparam colorArrowSeparationSpace 3\n:a;\n-[#red,dotted;#blue]-> two;\n:b;", ['two']];
        yield 'an empty diagram ending with an arrow' => ["if (a) then\nelse\n-> out;\nendif", ['a']];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function switches(): iterable
    {
        yield 'a switch' => [
            "start\nswitch (test?) <<#pink>>\ncase (A)\n  :one;\ncase (B)\n  -> b in;\n  :two;\ncase (C)\n  stop\ncase (D)\n  :four;\n  -> out;\nendswitch <<#gold>>\nstop",
            ['test?', 'A', 'b in', 'one', 'out'],
        ];
        yield 'loops across swimlanes' => [
            "|A|\nwhile (a)\n  |B|\n  :b;\nbackward :c;\nendwhile\n|A|\nwhile (d)\nendwhile\nwhile (e)\n  |B|\n  :f;\nendwhile\n|A|\nstop\nwhile (g)\n  :h;\nendwhile\n|B|\nstop",
            ['b', 'c', 'f', 'h'],
        ];
        yield 'a switch with notes' => ["switch (a)\nnote left: n\ncase (b)\n  :c;\nendswitch\nswitch (d)\nnote left: m\nnote right: o\ncase (e)\n  :f;\nendswitch", ['n', 'm', 'o']];
        yield 'a switch with a wide test' => ["switch (a test wider than all of the cases taken together?)\ncase (a)\n  :x;\ncase (b)\n  :y;\ncase (c)\n  :z;\nendswitch", ['x', 'y', 'z']];
        yield 'a switch of one case with a wide test' => ["switch (a test wider than its one case?)\ncase (a)\n  :x;\nendswitch <<#gold>>\n:after;", ['x', 'after']];
        yield 'a switch of one case never leaving' => ["switch (a)\ncase (b)\n  stop\nendswitch", ['a', 'b']];
        yield 'a switch never leaving' => ["switch (a)\ncase (b)\n  stop\ncase (c)\n  end\nendswitch", ['b', 'c']];
        yield 'a switch leaving from one case between' => ["switch (a)\ncase (b)\n  stop\ncase (c)\n  :x;\ncase (d)\n  stop\nendswitch", ['x']];
        yield 'a switch leaving from its first case' => ["switch (a)\ncase (b)\n  :x;\ncase (c)\n  stop\nendswitch", ['x']];
        yield 'a switch with cases wide and narrow' => ["switch (a)\ncase (b)\n  :a very wide action on the left;\ncase (c)\n  :x;\ncase (d)\n  :y;\ncase (e)\n  :z;\nendswitch", ['x']];
        yield 'a switch whose last case is left of its test' => ["switch (a)\ncase (b)\n  :wwwwwwww;\ncase (a long label on the last case)\n  :x;\nendswitch", ['a long label on the last case']];
        yield 'a switch whose last case leaves just right of its end' => ["switch (a)\ncase (b)\n  :".\str_repeat('w', times: 24).";\ncase (a long label on the last case)\n  :x;\nendswitch", ['x']];
        yield 'a loop breaking from a switch' => ["while (w?)\n  switch (s)\n  case (a)\n    :x;\n    break\n  case (b)\n    :y;\n  endswitch\nendwhile\n:after;", ['x', 'y', 'after']];
    }

    /** @param list<string> $texts */
    #[DataProvider('diagrams')]
    #[DataProvider('switches')]
    public function testEveryFeatureDraws(string $lines, array $texts): void
    {
        $shown = \array_map(static fn (Mark $mark): string => \trim((string) $mark->text), self::draw($lines)->marks);

        foreach ($texts as $text) {
            $this->assertContains($text, $shown);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function conditionStyles(): iterable
    {
        foreach (['diamond', 'InsideDiamond', 'inside'] as $style) {
            yield $style => [
                "skinparam conditionStyle {$style}\nif (a) then\n  :b;\nelse\n  :c;\nendif\nif (d) then\n  :e;\nendif\nwhile (f?)\n  :g;\nendwhile\nrepeat\n  :h;\nrepeat while (i?) is (yes) not (no)",
            ];
        }
    }

    #[DataProvider('conditionStyles')]
    public function testEveryConditionStyleDraws(string $lines): void
    {
        $shown = \array_map(static fn (Mark $mark): string => \trim((string) $mark->text), self::draw($lines)->marks);

        $this->assertContains('a', $shown);
        $this->assertContains('f?', $shown);
    }

    public function testASingleSwimlaneIsNoSwimlane(): void
    {
        $shown = \array_map(static fn (Mark $mark): string => \trim((string) $mark->text), self::draw("|Only|\nstart\n:a;\nstop")->marks);

        $this->assertNotContains('Only', $shown);
        $this->assertContains('a', $shown);
    }

    public function testSwimlanesDrawTheirTitlesAndTheirSteps(): void
    {
        $shown = \array_map(
            static fn (Mark $mark): string => \trim((string) $mark->text),
            self::draw(
                "skinparam swimlaneWidth 200\n|A|\nstart\n:a;\n|B|\n-> over;\n:b;\n|A|\nif (c?) then\n  :d;\nendif\nwhile (e?)\n  :f;\n  break\nendwhile\nfork\n  :g;\nfork again\n  :h;\nend fork\nstop",
            )->marks,
        );

        $this->assertSame(['A', 'B'], \array_values(\array_intersect($shown, ['A', 'B'])));
        $this->assertContains('over', $shown);
    }

    public function testBigSwimlaneTitlesReachAboveTheSwimlanesAsPlantUmlsLimitsTakeThem(): void
    {
        $top = static function (int $size): float {
            $drawing = self::draw("<style>\nswimlane {\n FontSize {$size}\n}\n</style>\n|A|\nstart\n:x;\n|B|\n:y;");
            $lines = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind);

            return \min(\array_map(static fn (Mark $mark): float => $mark->points[0][1], $lines)) / Units::POINT;
        };

        // As the jar draws them: from 16 down, then lower by what the title's descent passes 2.5 by.
        $this->assertEqualsWithDelta([16.0, 16.031, 17.297, 21.094], [$top(8), $top(12), $top(18), $top(36)], 1e-3);
    }

    public function testALabelOnAnArrowToTheNextStepTakesTheRoomPlantUmlsLimitsGiveIt(): void
    {
        $baseline = static function (int $size): float {
            $marks = self::draw("<style>\narrow {\n FontSize {$size}\n}\n</style>\n:one;\n-> lbl;\n:two;")->marks;
            $two = \array_values(\array_filter($marks, static fn (Mark $mark): bool => 'two' === $mark->text));

            return $two[0]->points[0][1] / Units::POINT;
        };

        // As the jar sets them: the label as tall as its line, and its descent past 1.5, 2.5 at most.
        $this->assertEqualsWithDelta([109.422, 113.01, 125.289, 137.066], [$baseline(8), $baseline(11), $baseline(20), $baseline(30)], 1e-3);
    }

    public function testALabelAtTheEndOfABranchStandsAsPlantUmlDrawsIt(): void
    {
        $at = static function (string $lines, string $text): float {
            $marks = \array_values(\array_filter(self::draw($lines)->marks, static fn (Mark $mark): bool => $text === $mark->text));

            return $marks[0]->points[0][1] / Units::POINT;
        };
        $if = static fn (int $size): string => "<style>\narrow {\n FontSize {$size}\n}\n</style>\nif (t?) then (yes)\n  :a;\n  -> out a;\nelse (no)\n  :b;\nendif\n:c;";

        // As the jar draws them: the label as low as it is taken to reach above its block, the step after past its whole descent.
        $this->assertEqualsWithDelta([106.363, 162.285, 187.281], [$at($if(11), text: 'out a'), $at($if(11), text: 'c'), $at($if(20), text: 'c')], 1e-3);
        $this->assertEqualsWithDelta(170.498, $at("start\nswitch (kind?)\ncase (a)\n  :first;\n  -> out of a;\ncase (b)\n  :second;\nendswitch\nstop", text: 'out of a'), 1e-3);
    }

    public function testABreakInAnotherSwimlaneThanItsRepeatLeavesByNoArrow(): void
    {
        $shown = \array_map(
            static fn (Mark $mark): string => \trim((string) $mark->text),
            self::draw("|A|\nrepeat\n  :a;\n  |B|\n  if (b) then\n    break\n  endif\n  |A|\nrepeat while (c)\n:d;")->marks,
        );

        $this->assertContains('d', $shown);
    }

    public function testElseIfsLeavingOnlyInOtherSwimlanesCloseThere(): void
    {
        $shown = \array_map(
            static fn (Mark $mark): string => \trim((string) $mark->text),
            self::draw("|A|\nif (a) then\n  |B|\n  :b;\nelseif (c) then\n  |C|\n  :d;\nelse\n  |B|\n  :e;\nendif")->marks,
        );

        $this->assertContains('d', $shown);
    }

    public function testDecisionsClosedByALineAcrossSwimlanesAreLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw a decision closed by a line across swimlanes'));

        self::draw("skinparam conditionEndStyle hline\n|A|\nif (a) then\n  :b;\nelse\n  |B|\n  :c;\nendif");
    }

    public function testAWideSwitchHoldingStepsOfOtherSwimlanesInOneOfItsOwnIsLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw a wide switch holding steps of other swimlanes'));

        self::draw("|A|\nswitch (a test much wider than its case)\ncase (b)\n  |B|\n  while (c)\n    |C|\n    :d;\n  endwhile\nendswitch");
    }

    public function testWhatStandsOutsideAnySwimlaneIsNotDrawn(): void
    {
        $shown = \array_map(static fn (Mark $mark): string => \trim((string) $mark->text), self::draw("note right: first\nsplit\nend split\n|A|\nstart\n:a;\n|B|\n:b;")->marks);

        $this->assertNotContains('first', $shown);
        $this->assertContains('b', $shown);
    }

    public function testASwitchWithoutCasesIsLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw a switch without cases'));

        self::draw("switch (a)\nendswitch");
    }

    public function testALabelOnAnArrowAcrossSwimlanesTakesTheRoomPlantUmlsCompressionGivesIt(): void
    {
        $baseline = static function (string $label): float {
            $marks = self::draw("|A|\n|C|\n|A|\nstart\n:one;\n{$label}\n|C|\n:two;")->marks;
            $two = \array_values(\array_filter($marks, static fn (Mark $mark): bool => 'two' === $mark->text));

            return $two[0]->points[0][1] / Units::POINT;
        };

        // As the jar sets them: the label's text down to 1.5 below its baseline, 10 clear of the arrow's head.
        $this->assertEqualsWithDelta(
            [174.715, 176.877, 182.928, 186.593],
            [
                $baseline('-> <size:8>across;'),
                $baseline('-> across;'),
                $baseline('-> <size:20>across;'),
                $baseline('-> across\nmore;'),
            ],
            1e-3,
        );
    }

    public function testACaseLabelTakesTheRoomPlantUmlsCompressionGivesIt(): void
    {
        $marks = self::draw("start\nswitch (which?)\ncase (left)\n  :one;\ncase (right)\n  :two;\nendswitch\n:after;")->marks;
        $at = static fn (string $text): float => \array_values(\array_filter($marks, static fn (Mark $mark): bool => $text === $mark->text))[0]->points[0][1] / Units::POINT;

        // As the jar sets them: the label from its line's height less 1.5 above its baseline, 10 below the diamond.
        $this->assertEqualsWithDelta([100.455, 143.557, 231.689], [$at('left'), $at('one'), $at('after')], 1e-3);
    }

    public function testStackedNotesBesideASwitchKeepTheirRoomFromCompression(): void
    {
        $three = static function (string $notes): float {
            $marks = self::draw("start\nswitch (again?)\n{$notes}\ncase (C)\n  :three;\ncase (D)\n  :four;\nendswitch")->marks;

            return \array_values(\array_filter($marks, static fn (Mark $mark): bool => 'three' === $mark->text))[0]->points[0][1] / Units::POINT;
        };

        // As the jar sets them: notes on both sides keep 10 clear below them, which holds the cases where they were laid out.
        $this->assertEqualsWithDelta(
            [144.214, 148.512, 148.512],
            [
                $three("note left: a\nnote right: b"),
                $three("note left\na\nb\nend note\nnote right: d"),
                $three("note left: a\nnote right\nc\nd\nend note"),
            ],
            1e-3,
        );
    }

    private static function draw(string $lines): Drawing
    {
        return Work::run(PlantUmlPipeline::native()->drawing("@startuml\n".$lines."\n@enduml"));
    }

    /**
     * The drawing's marks back in points from its frame's margin.
     *
     * @return list<Mark>
     */
    private static function points(Drawing $drawing): array
    {
        return \array_map(static fn (Mark $mark): Mark => new Mark(
            $mark->kind,
            \array_map(static fn (array $point): array => [($point[0] / Units::POINT) - self::MARGIN, ($point[1] / Units::POINT) - self::MARGIN], $mark->points),
            $mark->text,
        ), $drawing->marks);
    }

    /** @return array{float, float} the middle of a mark's points */
    private static function centre(Mark $mark): array
    {
        $xs = \array_column($mark->points, 0);
        $ys = \array_column($mark->points, 1);

        return [(\min($xs) + \max($xs)) / 2, (\min($ys) + \max($ys)) / 2];
    }
}
