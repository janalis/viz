<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Preprocessor;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\Preprocessor\PlantUmlPreprocessor;
use Janalis\Viz\PlantUML\Preprocessor\TimContext;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlPreprocessorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach ((array) \glob(__DIR__.'/../../fixtures/plantuml-preprocessor/*.puml') as $fixture) {
            yield \basename((string) $fixture, suffix: '.puml') => [(string) $fixture];
        }
    }

    #[DataProvider('fixtures')]
    public function testTheSourceIsExpandedAsPlantUmlExpandsIt(string $fixture): void
    {
        $lines = \explode("\n", \rtrim((string) \file_get_contents($fixture), characters: "\n"));

        // As PlantUML gives them out, its break lines split.
        $result = \array_merge(...\array_map(PlantUmlPreprocessor::broken(...), TimContext::process($lines)));

        $this->assertSame((string) \file_get_contents(\substr($fixture, offset: 0, length: -5).'.preproc'), \implode("\n", $result)."\n");
    }

    public function testALibraryFileIsIncludedWithoutItsHeaderAndASpriteOfItIsKnownOrNot(): void
    {
        $source = PlantUmlSource::parse("@startuml\n!include <C4/C4.puml>!sub\nsprite \$known #c4#c4_context:person\nsprite \$unknown #c4#nope\nclass A\n@enduml");
        $document = PlantUmlDocument::read($source);

        $this->assertContains('skinparam defaultTextAlignment center', $source->lines);
        $this->assertSame(['known'], \array_keys($document->styles->style(['root'])->sprites));
    }

    public function testWhatFunctionsWriteIsMadeTextTheDiagramsRead(): void
    {
        $source = PlantUmlSource::parse("@startuml\nA -> B : one%newline()two %backslash() %tab()x\nC -> D : %breakline()E -> F : {{%breakline()}}\n@enduml");

        $this->assertSame(['A -> B : one\ntwo \\ '."\t".'x', 'C -> D : ', 'E -> F : {{'."\u{E103}".'}}'], $source->lines);
    }

    /** @return iterable<string, array{string, string}> */
    public static function stops(): iterable
    {
        yield 'endif alone' => ['!endif', "PlantUML's preprocessor stops: No if related to this endif — !endif"];
        yield 'else alone' => ['!else', "PlantUML's preprocessor stops: No if related to this else — !else"];
        yield 'endwhile alone' => ['!endwhile', "PlantUML's preprocessor stops: No while related to this endwhile — !endwhile"];
        yield 'endfor alone' => ['!endfor', "PlantUML's preprocessor stops: No foreach related to this endforeach — !endfor"];
        yield 'endsub alone' => ['!endsub', "PlantUML's preprocessor stops: Compile Error null Endsub — !endsub"];
        yield 'a return outside a function' => ['!return 1', "PlantUML's preprocessor stops: Compile Error null Return — !return 1"];
        yield 'an unknown function' => ['!$a = %nope(1)', "PlantUML's preprocessor stops: Unknown built-in function %nope"];
        yield 'arguments its declaration does not take' => ['!$a = %xargs()', "PlantUML's preprocessor stops: Bad number of arguments for %xargs"];
        yield 'a failed assertion' => ['!assert 1 == 2 : "no"', "PlantUML's preprocessor stops: Assertion error : no — !assert 1 == 2 : \"no\""];
        yield 'a bare failed assertion' => ['!assert 0', "PlantUML's preprocessor stops: Assertion error — !assert 0"];
        yield 'an endless loop' => ["!while 1\n!endwhile", "PlantUML's preprocessor stops: Infinite loop? — !endwhile"];
        yield 'a procedure returning' => [
            "!procedure \$p()\n!return 1\n!endprocedure",
            "PlantUML's preprocessor stops: A procedure cannot have !return directive. Declare it as a function instead ? — !return 1",
        ];
        yield 'a function without return' => [
            "!function \$f()\nx\n!endfunction",
            "PlantUML's preprocessor stops: This function does not have any !return directive. Declare it as a procedure instead ? — !endfunction",
        ];
        yield 'a final function again' => ["!final function \$f() return 1\n!function \$f() return 2", "PlantUML's preprocessor stops: This function is already defined"];
        yield 'a nested sub' => ["!startsub A\n!startsub B\n!endsub", "PlantUML's preprocessor stops: Cannot nest sub — !startsub A"];
        yield 'an unknown sub' => ['!includesub NOPE', "PlantUML's preprocessor stops: cannot include NOPE — !includesub NOPE"];
        yield 'a bad sub name' => ['!startsub a-b', "PlantUML's preprocessor stops: Bad sub name — !startsub a-b"];
        yield 'bad JSON' => ['!$a = {"a": }', "PlantUML's preprocessor stops: Error in JSON format — !\$a = {\"a\": }@enduml"];
        yield 'a local variable outside a function' => ['!local $a = 1', "PlantUML's preprocessor stops: Cannot use local variable here"];
        yield 'a division by zero' => ['!$a = 1 / 0', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'JSON a foreach cannot read' => ['!foreach $x in [1,', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a procedure called as a function' => ["!procedure \$p()\n!endprocedure\n!\$x = %invoke_procedure(\"\$p\")", "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a function invoked as a procedure' => ['%invoke_procedure("%strlen", "x")', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a bad pattern' => ['!$x = %splitstr_regex("a", "[")', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a substring before the start' => ['!$x = %substr("abc", -1)', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a modulo by zero' => ['!$x = %mod(1, 0)', "PlantUML's preprocessor stops: Divide by zero"];
        yield 'no integer' => ['!$x = %intval("abc")', "PlantUML's preprocessor stops: Cannot convert abc to integer."];
        yield 'too large an integer' => ['!$x = %intval("99999999999")', "PlantUML's preprocessor stops: Cannot convert 99999999999 to integer."];
        yield 'text after an unquoted argument' => [
            "!unquoted procedure \$u(\$a)\n!endprocedure\n\$u(\"a\" b)",
            "PlantUML's preprocessor stops: unquoted function/procedure cannot use expression. — \$u(\"a\" b)",
        ];
        yield 'a function without parentheses' => ['!function $f', "PlantUML's preprocessor stops: Missing opening parenthesis — !function \$f"];
        yield 'a bad argument' => ['!procedure $p(1)', "PlantUML's preprocessor stops: Error in function definition — !procedure \$p(1)"];
        yield 'a bad ifdef' => ['!ifdef &', "PlantUML's preprocessor stops: Fatal parsing error — &"];
        yield 'a bad ifdef end' => ['!ifdef a b', "PlantUML's preprocessor stops: Fatal parsing error — a b"];
        yield 'an empty if' => ['!if', "PlantUML's preprocessor stops: Missing expression — !if"];
        yield 'no in' => ['!foreach $x on [1]', "PlantUML's preprocessor stops: a001 — !foreach \$x on [1]"];
        yield 'an unquoted call unclosed' => ["!unquoted procedure \$u(\$a)\n!endprocedure\n\$u(a", "PlantUML's preprocessor stops: until001 — \$u(a"];
        yield 'no name' => ['!undef 1', "PlantUML's preprocessor stops: a002 — !undef 1"];
        yield 'a call unclosed' => ["!function \$f(\$a) return 1\n\$f(1", "PlantUML's preprocessor stops: until001 — \$f(1"];
        yield 'a bad word' => ['!$x = a@b', "PlantUML's preprocessor stops: Parsing syntax error about a@b"];
        yield 'a function declared in a procedure' => ["!procedure \$p()\n!function \$f() return 1\n!endprocedure", "PlantUML's preprocessor stops: already0068"];
        yield 'keys of a number' => ['!$x = %get_json_keys(%str2json("1"))', "PlantUML's preprocessor stops: Bad JSON type"];
        yield 'merging a number' => ['!$x = %json_merge(%str2json("{}"), %str2json("1"))', "PlantUML's preprocessor stops: Bad JSON type"];
        yield 'a JSON literal in text' => ["!\$t = %str2json(\"true\")\nx \$t", "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'a field of an array' => ["!\$a = [1]\nx \$a.f", "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'an index into a number' => ["!\$a = {\"n\": 1}\nx \$a.n[0]", "PlantUML's preprocessor stops: Major parsing error"];
        yield 'a foreach over a number' => ['!foreach $x in %str2json("1")', "PlantUML's preprocessor stops: Fatal parsing error — !foreach \$x in %str2json(\"1\")"];
        yield 'an empty while' => ['!while', "PlantUML's preprocessor stops: Missing expression — !while"];
        yield 'a sub never ended' => ["!startsub A\nx", "PlantUML's preprocessor stops: Compile Error null Startsub — !startsub A"];
        yield 'an unknown named argument' => ["!function \$f(\$a) return 1\n\$f(\$z = 1)", "PlantUML's preprocessor stops: Function not found \$f — \$f(\$z = 1)"];
        yield 'a procedure in an expression' => ["!procedure \$p()\n!endprocedure\n!\$x = \$p()", "PlantUML's preprocessor stops: Illegal call here. Is there a return directive in your function?"];
        yield 'a function invoked' => ["!function \$f() return 1\n%invoke_procedure(\"\$f\")", "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'too large a number' => ['!$x = 99999999999', "PlantUML's preprocessor stops: Fatal parsing error"];
        yield 'an integer past Java\'s' => ['!$x = %intval("9999999999")', "PlantUML's preprocessor stops: Cannot convert 9999999999 to integer."];
        yield 'a library PlantUML lacks' => ['!include <nope/file>', "PlantUML's preprocessor stops: cannot include <nope/file> — !include <nope/file>"];
        yield 'a file a library lacks' => ['!include_once <C4/nope>', "PlantUML's preprocessor stops: cannot include <C4/nope> — !include_once <C4/nope>"];
        yield 'no library' => ['!include <C4>', "PlantUML's preprocessor stops: cannot include <C4> — !include <C4>"];
        yield 'no colour' => ['!$a = %darken("nope", 1)', "PlantUML's preprocessor stops: No such color"];
    }

    #[DataProvider('stops')]
    public function testWherePlantUmlsPreprocessorStopsTheDiagramIsLeftToIt(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        PlantUmlPreprocessor::lines(['@startuml', ...\explode("\n", $lines), '@enduml']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function leftToPlantUml(): iterable
    {
        yield 'a theme not under MIT' => ['!theme amiga', 'The native PlantUML renderer leaves the theme amiga to PlantUML'];
        yield 'a theme from elsewhere' => ['!theme cerulean from themes', 'The native PlantUML renderer leaves !theme … from to PlantUML: !theme cerulean from themes'];
        yield 'include' => ['!include foo.puml', 'The native PlantUML renderer leaves !include of a file or a URL to PlantUML: !include foo.puml'];
        yield 'a file named with a u' => ['!include utils.puml', 'The native PlantUML renderer leaves !include of a file or a URL to PlantUML: !include utils.puml'];
        yield 'include of a URL' => ['!includeurl https://example.com/a.puml', 'The native PlantUML renderer leaves !include of a file or a URL to PlantUML: !includeurl https://example.com/a.puml'];
        yield 'a library not under MIT' => ['!include <aws/common>', 'The native PlantUML renderer leaves the standard library aws to PlantUML'];
        yield 'a library linking to one not under MIT' => ['!include_many <awslib/AWSCommon>', 'The native PlantUML renderer leaves the standard library awslib14 to PlantUML'];
        yield 'include of a sub of a file' => ['!includesub foo.puml!PART', 'The native PlantUML renderer leaves !includesub of a file to PlantUML: !includesub foo.puml!PART'];
        yield 'the clock' => ['%date()', 'The native PlantUML renderer leaves %date() to PlantUML'];
        yield 'an option' => ['!option handwritten true', 'The native PlantUML renderer leaves !option to PlantUML: !option handwritten true'];
        yield 'a gradient darkened' => ['!$a = %darken("red/blue", 10)', 'The native PlantUML renderer leaves colour functions of red/blue to PlantUML'];
        yield 'a directive for the diagram' => ['!assume opaque', 'The native PlantUML renderer leaves !assume to PlantUML'];
    }

    #[DataProvider('leftToPlantUml')]
    public function testWhatIsNotDoneNativelyIsLeftToPlantUml(string $lines, string $message): void
    {
        $this->expectExceptionObject(new Unsupported($message));

        PlantUmlSource::parse("@startuml\n".$lines."\n@enduml");
    }
}
