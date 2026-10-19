<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Mermaid\EventModelingDiagram;
use Janalis\Viz\Mermaid\EventModelingLabels;
use Janalis\Viz\Mermaid\EventModelingLayout;
use Janalis\Viz\Mermaid\EventModelingLexer;
use Janalis\Viz\Mermaid\EventModelingParser;
use Janalis\Viz\Mermaid\EventModelingState;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSyntaxError;

final class EventModelingTest extends TestCase
{
    private static function parse(string $source): EventModelingDiagram
    {
        $parser = new EventModelingParser();
        foreach (new EventModelingLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/eventmodeling-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/eventmodeling/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    public static function invalidBodies(): iterable
    {
        foreach ([
            'Unexpected',
            'entity A B',
            'tf 01',
            'tf 01 wrong Entity',
            'tf a ui Entity',
            'tf 0000 ui Entity',
            'tf 01 ui cmd',
            'tf 01 ui A.',
            'tf 01 ui .A',
            'tf 01 ui 123',
            'tf 01 ui A ->>',
            'tf 01 ui A [[Missing',
            'tf 01 ui A [[01]]',
            'tf 01 ui A `bad`{value:1}',
            'tf 01 ui A `json`',
            'tf 01 ui A unknown',
            'tf 01 ui A\nrf 01 evt B',
            'entity',
            'entity 01',
            'data D {}',
            'data D `json` {}',
            'note A {\ntext\n}',
            'note 01 {}',
            'gwt 01 given then evt E',
            'gwt 01 given evt E',
            'gwt 01 given evt E when then evt E',
            'gwt 01 given evt E then',
            'tf 01 ui A;',
            'tf 01 ui A "unclosed',
            '/* unclosed',
        ] as $body) {
            yield $body => [\str_replace('\n', replace: "\n", subject: $body)];
        }
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidGrammarAndDuplicateFrames(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("eventmodeling\n".$body);
    }

    public static function invalidHeaders(): iterable
    {
        yield [''];
        yield ['flowchart'];
    }

    #[DataProvider('invalidHeaders')]
    public function testMissingOrIncorrectHeadersFail(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testMalformedRolePointsAtTheConsumedRoleToken(): void
    {
        $source = "eventmodeling\ntf\n01\nwrong\nEntity";
        try {
            self::parse($source);
            $this->fail('Expected a located role error');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame(\strpos($source, needle: 'wrong'), $error->offset);
            $this->assertSame(3, $error->sourceLine);
        }
    }

    public function testCommentPrefixesAndAuthoredTitleRenderNatively(): void
    {
        $source = "/* before */\n// another\neventmodeling title Native model %% hidden\ntf 01 ui Screen";
        $diagram = self::parse($source);
        $this->assertSame(3, $diagram->frames[0]['statement']->line);
        $this->assertArrayHasKey('title', $diagram->metadata);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('Native model', $svg);
        $this->assertStringNotContainsString('hidden', $svg);
    }

    public static function boundedBodies(): iterable
    {
        yield 'declarations' => [\str_repeat("entity E\n", times: 4097)];
        yield 'fields' => ['gwt 01 given '.\str_repeat('evt E ', times: 4097).'then evt E'];
        yield 'token bytes' => ['tf 01 ui E "'.\str_repeat('x', times: 65_537).'"'];
        yield 'token work' => [\str_repeat('/**/', times: 131_073)];
    }

    #[DataProvider('boundedBodies')]
    public function testBoundsRejectExcessWork(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("eventmodeling\n".$body);
    }

    public function testQualifiedLaneBounds(): void
    {
        $source = "eventmodeling\n";
        foreach (\range(1, end: 100) as $id) {
            $source .= 'tf '.$id." ui Namespace.Screen\n";
        }
        $diagram = self::parse($source);
        $this->expectException(MermaidSyntaxError::class);
        \iterator_to_array(EventModelingState::resolve($diagram));
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesExportNativeSvg(string $path, array $expected): void
    {
        $svg = NativeFixtureExport::render(\file_get_contents($path), Format::Svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertNotEmpty($expected);
    }

    public function testDeclaredEntitiesAndAccessibilityRemainReadable(): void
    {
        $source = "eventmodeling accTitle: Model semantics\nentity Order\ntf 01 evt OrderCreated";
        $diagram = self::parse($source);
        $legend = EventModelingLabels::legend($diagram);
        \iterator_to_array($legend);
        $texts = \array_column($legend->getReturn(), 'text');
        $this->assertContains('accTitle: Model semantics', $texts);
        $this->assertContains('entity Order', $texts);
        $this->assertContains('tf 01 evt OrderCreated', $texts);
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new EventModelingLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testExplicitInputsDoNotFallBackAndFollowSourceDeclarationOrder(): void
    {
        $diagram = self::parse("eventmodeling\ntf 01 ui Screen\ntf 02 cmd Run ->> 99\ntf 03 evt Done ->> 02 ->> 02 ->> 01\nrf 04 ui Reset\ntf 05 cmd Again");
        $work = EventModelingState::resolve($diagram);
        \iterator_to_array($work);
        $this->assertSame([['from' => 0, 'to' => 2], ['from' => 1, 'to' => 2], ['from' => 3, 'to' => 4]], $work->getReturn()->relations);
    }

    public function testFutureInputsRemainUnresolvedUntilTheirFrameExists(): void
    {
        $diagram = self::parse("eventmodeling\ntf 01 cmd Run ->> 02\ntf 02 evt Done\nrf 03 evt Other");
        $work = EventModelingState::resolve($diagram);
        \iterator_to_array($work);
        $this->assertSame([['from' => 0, 'to' => 1]], $work->getReturn()->relations);
    }

    public function testFrameIdPaddingIsSignificantAndSelfInputIsRetained(): void
    {
        $diagram = self::parse("eventmodeling\ntf 1 ui Screen\ntf 01 cmd Run ->> 01");
        $this->assertSame(['1', '01'], \array_column($diagram->frames, 'name'));
        $work = EventModelingState::resolve($diagram);
        \iterator_to_array($work);
        $this->assertSame([['from' => 1, 'to' => 1]], $work->getReturn()->relations);
    }

    public function testDataReferencesUseTheFirstDeclarationAndOverrideInlineData(): void
    {
        $source = "eventmodeling\ntf 01 cmd Example [[Payload]] {inline:ignored}\ndata Payload {\nfirst: true\n}\ndata Payload {\nsecond: ignored\n}\n";
        $scene = self::scene($source);
        $label = $scene->graph->nodes[0]->label;
        $this->assertStringContainsString('first: true', $label);
        $this->assertStringNotContainsString('inline', $label);
        $this->assertStringNotContainsString('second', $label);
        $this->assertStringStartsWith('er-card:', $scene->shapes[0]);
    }

    public function testReturningFramesDoNotOverlapAndLaneHeadersAreNotSelectable(): void
    {
        $source = "eventmodeling\ntf 01 ui Screen\ntf 02 cmd Run\ntf 03 ui ScreenAgain\ntf 04 cmd RunAgain";
        $diagram = self::parse($source);
        $before = \serialize($diagram);
        $work = new EventModelingLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame($before, \serialize($diagram));
        $this->assertCount(4, $scene->selectionGraph->nodes);
        $this->assertCount(6, $scene->graph->nodes);
        foreach ([[0, 2], [1, 3]] as [$first, $last]) {
            $a = $scene->graph->nodes[$first];
            $b = $scene->graph->nodes[$last];
            $this->assertSame($a->y, $b->y);
            $this->assertGreaterThanOrEqual($a->x + ($a->w / 2) + 2, $b->x - ($b->w / 2));
            $this->assertSame($diagram->frames[$last]['statement']->line, $scene->elements[$last]);
        }
    }

    public function testOpaqueHtmlDataIsEscapedAndPngRendersCards(): void
    {
        $source = 'eventmodeling'."\n".'tf 01 ui Screen `html`{<script>bad()</script>}'."\n".'tf 02 evt Changed';
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $png = NativeFixtureExport::render($source, Format::Png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function testNotesAndCriteriaKeepEveryAuthoredClauseInTheLegend(): void
    {
        $source = \file_get_contents(__DIR__.'/../fixtures/mermaid/eventmodeling/parser-01.mmd');
        $texts = \array_column(self::scene($source)->legend, 'text');
        $this->assertContains('01 Given evt CartUpdatedEvent', $texts);
        $this->assertContains('01 When evt ProductDescriptionUpdatedEvent', $texts);
        $this->assertContains('03 Then evt CartUpdatedEvent', $texts);
        $this->assertTrue(\array_any($texts, static fn (string $text): bool => \str_starts_with($text, 'note 02:') && \str_contains($text, 'markdown note')));
    }
}
