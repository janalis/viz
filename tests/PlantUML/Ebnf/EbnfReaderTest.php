<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Ebnf;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\Ebnf\AlternationTile;
use Janalis\Viz\PlantUML\Ebnf\BoxTile;
use Janalis\Viz\PlantUML\Ebnf\CirclesTile;
use Janalis\Viz\PlantUML\Ebnf\EbnfExpression;
use Janalis\Viz\PlantUML\Ebnf\EbnfRule;
use Janalis\Viz\PlantUML\Ebnf\EbnfStyle;
use Janalis\Viz\PlantUML\Ebnf\EbnfToken;
use Janalis\Viz\PlantUML\Ebnf\PlantUmlEbnf;
use Janalis\Viz\PlantUML\Ebnf\PlantUmlRegex;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EbnfReaderTest extends TestCase
{
    public function testRulesTokenizeAsPlantUmlReadsThem(): void
    {
        $expression = EbnfExpression::read('a-b = "x" | \'y\', ? any ?, - c, { d }-, [ e ], 3 * f (* note *); ignored');

        $this->assertSame('a-b', $expression->name());
        $this->assertSame(
            [
                'LITERAL',
                'DEFINITION',
                'TERMINAL_STRING1',
                'ALTERNATION',
                'TERMINAL_STRING2',
                'CONCATENATION',
                'SPECIAL_SEQUENCE',
                'CONCATENATION',
                'NOT',
                'LITERAL',
                'CONCATENATION',
                'REPETITION_OPEN',
                'LITERAL',
                'REPETITION_MINUS_CLOSE',
                'CONCATENATION',
                'OPTIONAL_OPEN',
                'LITERAL',
                'OPTIONAL_CLOSE',
                'CONCATENATION',
                'LITERAL',
                'REPETITION_SYMBOL',
                'LITERAL',
                'COMMENT_TOKEN',
            ],
            \array_map(static fn (EbnfToken $item): string => $item->kind, $expression->tokens),
        );
        $this->assertSame(' note ', $expression->tokens[22]->data);
    }

    public function testTextStandsOnItsBaselineAsPlantUmlSetsIt(): void
    {
        $baselines = [];
        foreach (Work::run(PlantUmlPipeline::native()->drawing("@startebnf\nrule = \"a\";\n@endebnf"))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind) {
                continue;
            }
            $baselines[$mark->text] = $mark->points[0][1] / Units::POINT;
        }

        // As the jar draws them: each baseline its line's descent above its foot.
        $this->assertEqualsWithDelta(['rule' => 23.535, 'a' => 55.023], $baselines, 1e-3);
    }

    public function testAnEmptyStringIsASpace(): void
    {
        $this->assertSame(' ', EbnfExpression::read('a = "";')->tokens[2]->data);
    }

    /** @return iterable<string, array{string}> */
    public static function unparsable(): iterable
    {
        yield 'a character it does not read' => ['a = b @ c;'];
        yield 'an open string' => ['a = "b;'];
        yield 'nothing' => ['  ;'];
    }

    #[DataProvider('unparsable')]
    public function testItRefusesWhatPlantUmlCannotParse(string $text): void
    {
        $this->expectException(PlantUmlRefusal::class);

        EbnfExpression::read($text);
    }

    /** @return iterable<string, array{string}> */
    public static function syntaxErrors(): iterable
    {
        yield 'no definition' => ['a b;'];
        yield 'a bracket left open' => ['a = [ b;'];
        yield 'a bracket closed twice' => ['a = b ];'];
        yield 'two terms side by side' => ['a = b c;'];
        yield 'a group left open' => ['a = ( b;'];
    }

    #[DataProvider('syntaxErrors')]
    public function testWhatPlantUmlCannotBuildIsASyntaxError(string $text): void
    {
        $this->assertNull(EbnfExpression::read($text)->tile(new EbnfStyle(StyleSheet::skin())));
    }

    public function testAlternativesAndConcatenationsGatherTheirTerms(): void
    {
        $tile = EbnfExpression::read('a = b | c | d;')->tile(new EbnfStyle(StyleSheet::skin()));

        $this->assertInstanceOf(CirclesTile::class, $tile);
        // Three boxes stacked 10 apart.
        $this->assertEqualsWithDelta((3 * PlantUmlTestFont::line()) + 20, $tile->height(), 0.001);
    }

    public function testCommentsAndRulesAreReadInOrder(): void
    {
        $diagram = self::ebnf(<<<'PUML'
            (* alone *)
            (** documenting
            over lines **)
            (* above *) rule (* below *) = "a";
            long =
              "b",
              "c";
            PUML);

        $this->assertSame(['alone'], $diagram->items[0]);
        $this->assertSame(['documenting', 'over lines'], $diagram->items[1]);
        $rule = $diagram->items[2];
        $this->assertInstanceOf(EbnfRule::class, $rule);
        $this->assertSame(['rule', ' above ', ' below '], [$rule->expression->name(), $rule->above, $rule->below]);
        $long = $diagram->items[3];
        $this->assertInstanceOf(EbnfRule::class, $long);
        $this->assertSame('long', $long->expression->name());
    }

    public function testASyntaxErrorIsDrawnAsPlantUmlWritesIt(): void
    {
        $drawing = Work::run(self::ebnf('a = [ b;')->drawing());
        $texts = \array_map(static fn ($mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)));

        $this->assertSame(['Syntax error!'], $texts);
    }

    public function testTheModelListsRulesAndNotes(): void
    {
        $graph = self::ebnf("(* note *)\na = \"b\";")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(
            [
                ['n0', 'note', Shape::Note],
                ['n1', 'a',    Shape::Box],
            ],
            \array_map(static fn (Node $node): array => [$node->id, $node->label, $node->shape], $graph->nodes),
        );
    }

    public function testRegexLettersRunTogetherAndQuantifiersWrapTheirTerm(): void
    {
        $diagram = self::regex('abc|de+');
        $tile = $diagram->tile;

        $this->assertInstanceOf(AlternationTile::class, $tile);
        $texts = \array_map(static fn ($mark): string => $mark->text, \array_values(\array_filter(Work::run($diagram->drawing())->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)));
        $this->assertSame(['abc', 'd', 'e'], $texts);
    }

    public function testRegexClassesGroupsAndCountsAreDrawn(): void
    {
        $texts = \array_map(
            static fn ($mark): string => $mark->text,
            \array_values(\array_filter(Work::run(self::regex('(?<name>[^a-c\]]\d{2,3})(?!x)')->drawing())->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)),
        );

        $this->assertSame(['^', 'a-c', ']', '\d', '{2,3}', 'name', '?!', 'x'], $texts);
    }

    public function testEachRegexLineBuildsOnTheOneBefore(): void
    {
        $this->assertInstanceOf(BoxTile::class, self::regex("ab\ncd")->tile);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badRegexes(): iterable
    {
        yield 'a quantifier on nothing' => ['*', 'nothing to quantify'];
        yield 'a bad count' => ['a{x}', 'Bad quantifier {x}'];
        yield 'a lazy count' => ['a{2}?', 'Bad quantifier {2}?'];
        yield 'an open comment' => ['a(?#b', 'Unclosed comment'];
        yield 'a group left open' => ['(a', 'PARENTHESIS_OPEN'];
        yield 'a group closed twice' => ['a)', 'unbalanced )'];
        yield 'nothing' => ['', 'The regex diagram is empty'];
        yield 'a group named with no letters' => ['(?<1x>a)', 'nothing to quantify'];
        yield 'a group named and never closed' => ['(?<abc', 'nothing to quantify'];
        yield 'an octal escape cut short' => ['\\01', 'Unexpected end of data'];
        yield 'a unicode class never closed' => ['\\p{L', 'Unexpected end of data'];
        yield 'a command it does not draw' => ["a\nhide empty description", 'does not draw: hide empty description'];
    }

    #[DataProvider('badRegexes')]
    public function testItRefusesWhatPlantUmlFailsOn(string $line, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        PlantUmlPipeline::native()->diagram("@startregex\n".$line."\n@endregex");
    }

    public function testAGroupOnEitherSideJoinsTheTermsBesideIt(): void
    {
        $style = new EbnfStyle(StyleSheet::skin());

        // As PlantUML's engine, a group alternated or concatenated with a term takes it in.
        $this->assertInstanceOf(CirclesTile::class, EbnfExpression::read('a = (b | c) | d;')->tile($style));
        $this->assertInstanceOf(CirclesTile::class, EbnfExpression::read('a = (b, c), d;')->tile($style));
        $this->assertInstanceOf(CirclesTile::class, EbnfExpression::read('a = (b | c) * d;')->tile($style), 'a count that is no word counts as ?');
        $this->assertNull(EbnfExpression::read('a = b = c;')->tile($style), 'a definition within a definition');
    }

    public function testNotesAroundTermsOptionsAndRulesAreDrawn(): void
    {
        $texts = static fn (string $lines): array => \array_values(\array_map(
            static fn ($mark): string => $mark->text,
            \array_filter(Work::run(self::ebnf($lines)->drawing())->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind),
        ));

        // As PlantUML writes them, each note as written between its marks.
        $this->assertSame(['a', 'x', ' note '], $texts('a = (* note *) "x";'));
        $this->assertSame(['a', 'x', ' up ', ' down '], $texts('a = (* up *) [ "x" ] (* down *);'));
        $this->assertSame([' above ', 'rule', 'a'], $texts('(* above *) rule = "a";'));
        $this->assertSame(['a'], $texts('a = "";'), 'an empty string is a blank box');
    }

    public function testBlankLinesCommentsAndNothingAreReadAsPlantUmlReadsThem(): void
    {
        $diagram = self::ebnf("(* a\nb *)\n\nrule = \"x\";");

        $this->assertSame(['a', 'b'], $diagram->items[0]);
        $this->assertCount(2, $diagram->items);
        $texts = \array_map(static fn ($mark): string => $mark->text, Work::run(self::ebnf('')->drawing())->marks);
        $this->assertSame(['Syntax error!'], $texts, 'nothing at all is a syntax error');
    }

    public function testItLeavesToPlantUmlTheCommandsItDoesNotDraw(): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage('does not draw: hide empty description');

        self::ebnf("a = \"x\";\nhide empty description");
    }

    public function testRegexGroupsJoinTheTermsBesideThem(): void
    {
        $texts = static function (string $regex): array {
            $texts = \array_values(\array_map(
                static fn ($mark): string => $mark->text,
                \array_filter(Work::run(self::regex($regex)->drawing())->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind),
            ));
            \sort($texts);

            return $texts;
        };

        // As PlantUML runs them, a group alternated takes the other in, letters run together across a group's first, a comment leaves nothing.
        $this->assertSame(['a', 'b', 'c'], $texts('(a|b)|c'));
        $this->assertSame(['a', 'b', 'c'], $texts('a|(b|c)'));
        $this->assertSame(['ab', 'c'], $texts('a(?:bc*)'));
        $this->assertSame(['ab'], $texts('a(?#x)b'));
        $this->assertSame(['4', '\0123', '\u0042'], $texts('\01234\u0042'));
    }

    public function testTheRegexModelIsOneBoxAsWritten(): void
    {
        $graph = self::regex("title Mail\na+b")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame([['n0', 'a+b']], \array_map(static fn (Node $node): array => [$node->id, $node->label], $graph->nodes));
        $this->assertSame('Mail', $graph->title);
    }

    private static function ebnf(string $lines): PlantUmlEbnf
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startebnf\n".$lines."\n@endebnf");
        self::assertInstanceOf(PlantUmlEbnf::class, $diagram);

        return $diagram;
    }

    private static function regex(string $lines): PlantUmlRegex
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startregex\n".$lines."\n@endregex");
        self::assertInstanceOf(PlantUmlRegex::class, $diagram);

        return $diagram;
    }
}
