<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Mermaid\ArchitectureConfiguration;
use Janalis\Viz\Mermaid\ArchitectureDiagram;
use Janalis\Viz\Mermaid\ArchitectureLayout;
use Janalis\Viz\Mermaid\ArchitectureLexer;
use Janalis\Viz\Mermaid\ArchitectureParser;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class ArchitectureTest extends TestCase
{
    private static function parse(string $source): ArchitectureDiagram
    {
        $parser = new ArchitectureParser();
        foreach (new ArchitectureLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/architecture-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/architecture/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesExportNativeSvg(string $path, array $expected): void
    {
        $svg = NativeFixtureExport::render(\file_get_contents($path), Format::Svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertNotEmpty($expected);
    }

    public static function invalidBodies(): iterable
    {
        foreach ([
            'service a\nservice a',
            'group a\njunction a',
            'service a in a',
            'service a in missing',
            'service a\nservice b in a',
            'group a in a',
            'junction j in missing',
            'group a\nservice b in a\nservice b',
            'service a\na:R -- L:missing',
            'group g\nservice a\ng:R -- L:a',
            'group g\nservice a in g\nservice b in g\na{group}:R -- L:b',
            'service a\nservice b\nalign row a b ghost',
            'service a\nalign row a a',
            'service a\nalign row a',
            'group g\nservice a\nalign column g a',
            'service a[]',
            'service a-',
            'service R',
            'service Team',
            'service title1',
            'service align',
            'service row',
            'service column',
            'service in',
            'service !',
            'junction j(server)',
            'junction j[Label]',
            'group g "text"',
            'service a trailing',
            'service a\nservice b\na:Q -- L:b',
            'service a\nservice b\na:R --> b',
            'accTitle invalid',
            'accDescr {unclosed',
        ] as $body) {
            yield $body => [\str_replace('\n', replace: "\n", subject: $body)];
        }
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsMalformedOrUnresolvedStatements(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("architecture-beta\n".$body);
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new ArchitectureLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testCombinedAlignmentsPreserveColumnsAndSeparateRows(): void
    {
        $source = \file_get_contents(__DIR__.'/../fixtures/mermaid/architecture/should-render-a-grid-via-combined-row-column-alignments.mmd');
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        foreach ([1, 2, 3] as $index) {
            $this->assertSame($nodes['a'.$index]->x, $nodes['b'.$index]->x);
            $this->assertGreaterThan($nodes['a'.$index]->y, $nodes['b'.$index]->y);
            $this->assertSame($nodes['a1']->y, $nodes['a'.$index]->y);
            $this->assertSame($nodes['b1']->y, $nodes['b'.$index]->y);
        }
        $this->assertGreaterThan($nodes['a1']->x + $nodes['a1']->w, $nodes['a2']->x);
        $this->assertGreaterThan($nodes['a2']->x + $nodes['a2']->w, $nodes['a3']->x);
    }

    public function testSamePortPeersStayDistinctInTheirAuthoredColumn(): void
    {
        $source = "architecture-beta\nservice a(database)\nservice b(database)\nservice c(server)\na:R --> L:c\nb:R --> L:c\nalign column a b";
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame($nodes['a']->x, $nodes['b']->x);
        $this->assertGreaterThan($nodes['a']->y + $nodes['a']->h, $nodes['b']->y);
        $this->assertGreaterThan($nodes['a']->x, $nodes['c']->x);
    }

    public function testSelfLoopsSamePortsAndTextIconsRemainVisible(): void
    {
        $source = "architecture-beta\nservice a \"PHP\"[Runtime]\nservice b\na:R <--> R:a\na:R -- R:b";
        $scene = self::scene($source);
        $this->assertCount(3, $scene->graph->edges);
        $this->assertNotEmpty(NativeFixtureExport::render($source, Format::Png));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('PHP', $svg);
        $this->assertStringContainsString('Runtime', $svg);
    }

    public static function boundedBodies(): iterable
    {
        yield 'symbols' => [\implode("\n", \array_map(static fn (int $i): string => 'service n'.$i, \range(0, end: 4096)))];
        yield 'edges' => ["service a\nservice b\n".\str_repeat("a:R -- L:b\n", times: 4097)];
        yield 'alignments' => ["service a\nservice b\n".\str_repeat("align row a b\n", times: 4097)];
        yield 'statements' => [\str_repeat("accTitle: data\n", times: 16_384)];
        yield 'plain size' => ['service n '.\str_repeat('x', times: 65_537)];
        yield 'label size' => ['service n['.\str_repeat('x', times: 65_537).']'];
        $groups = ['group g0'];
        for ($index = 1; $index <= 130; ++$index) {
            $groups[] = 'group g'.$index.' in g'.($index - 1);
        }
        yield 'depth' => [\implode("\n", $groups)];
    }

    #[DataProvider('boundedBodies')]
    public function testBoundsRejectExcessWork(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("architecture-beta\n".$body);
    }

    public static function invalidHeaders(): iterable
    {
        yield [''];
        yield ['architecture'];
    }

    #[DataProvider('invalidHeaders')]
    public function testMissingOrIncorrectHeadersFail(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testConflictingAlignmentsStillKeepGlyphsSeparate(): void
    {
        $scene = self::scene("architecture-beta\nservice a\nservice b\nalign row a b\nalign column a b");
        $nodes = \array_values($scene->graph->nodes);
        $this->assertSame($nodes[0]->x, $nodes[1]->x);
        $this->assertGreaterThan($nodes[0]->y + $nodes[0]->h, $nodes[1]->y);
    }

    public function testSeededConfigurationIsStableAndOverlaysPreserveZeroAndFalse(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $first = MermaidConfiguration::parse((object) ['architecture' => (object) ['seed' => 42, 'randomize' => true]], $statement);
        $later = MermaidConfiguration::parse((object) ['architecture' => (object) ['seed' => 0, 'randomize' => false]], $statement);
        $merged = $first->overlay($later);
        $this->assertSame(0, $merged->architecture->seed);
        $this->assertFalse($merged->architecture->randomize);
        $this->assertTrue($merged->architecture->configured());
        $keys = ['a', 'b', 'c', 'd'];
        $rng = new Randomizer(new Mt19937(7));
        $order = $merged->architecture->order($keys, '', $rng);
        $this->assertEqualsCanonicalizing($keys, $order);
        $this->assertNotSame($keys, $order);
        $this->assertSame($order, $merged->architecture->order($keys, '', new Randomizer(new Mt19937(7))));
        $this->assertSame($first->architecture->order($keys, '', $rng), $first->architecture->order($keys, '', $rng));
        $this->assertNotSame($keys, $first->architecture->order($keys, '', $rng));
        $this->expectException(MermaidSyntaxError::class);
        $merged->validateFamily('usecase');
    }

    public static function invalidConfigurations(): iterable
    {
        yield [true];
        yield [['seed' => -1]];
        yield [['seed' => 1.5]];
        yield [['randomize' => 1]];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationValuesFail(mixed $architecture): void
    {
        $this->expectException(MermaidSyntaxError::class);
        MermaidConfiguration::parse((object) ['architecture' => \is_array($architecture) ? (object) $architecture : $architecture], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testSeedChangesNativeTiePlacementWithoutMutatingDeclarations(): void
    {
        $diagram = self::parse("architecture-beta\nservice a\nservice b\nservice c\nservice d");
        $before = \serialize($diagram);
        $plain = new ArchitectureLayout()->layout($diagram);
        \iterator_to_array($plain);
        $configured = new ArchitectureLayout(new ArchitectureConfiguration(seed: 42))->layout($diagram);
        \iterator_to_array($configured);
        $repeat = new ArchitectureLayout(new ArchitectureConfiguration(seed: 42))->layout($diagram);
        \iterator_to_array($repeat);
        $this->assertNotEquals($plain->getReturn()->graph->nodes, $configured->getReturn()->graph->nodes);
        $this->assertEquals($repeat->getReturn(), $configured->getReturn());
        $this->assertSame($before, \serialize($diagram));
    }

    public function testOpposingPortsAlignTheirPerpendicularAxis(): void
    {
        $scene = self::scene("architecture-beta\nservice c\nservice left\nservice right\nservice top\nservice bottom\nc:L --> R:left\nc:R --> L:right\nc:T --> B:top\nc:B --> T:bottom");
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame($nodes['c']->y, $nodes['left']->y);
        $this->assertSame($nodes['c']->y, $nodes['right']->y);
        $this->assertSame($nodes['c']->x, $nodes['top']->x);
        $this->assertSame($nodes['c']->x, $nodes['bottom']->x);
        $this->assertLessThan($nodes['c']->x, $nodes['left']->x);
        $this->assertGreaterThan($nodes['c']->x, $nodes['right']->x);
        $this->assertLessThan($nodes['c']->y, $nodes['top']->y);
        $this->assertGreaterThan($nodes['c']->y, $nodes['bottom']->y);
        foreach ($scene->graph->edges as $edge) {
            $this->assertNotNull($edge->arrowTip);
        }
    }

    public function testNativeResourceGlyphsAndLabelMasksAreDistinct(): void
    {
        $scene = self::scene(
            "architecture-beta\nservice srv(server)[Server]\nservice db(database)[Database]\nservice disk(disk)[Disk]\nservice net(internet)[Internet]\nservice cloud(cloud)[Cloud]\nservice missing(external:missing)[Unknown]",
        );
        $marks = [];
        foreach ($scene->graph->nodes as $node) {
            $marks[$node->name] = MermaidOutline::marks($node, $scene->shapes[$node->id]);
            $this->assertCount(1, \array_filter($marks[$node->name], static fn (Mark $mark): bool => MarkKind::Text === $mark->kind && $node->label === $mark->text));
            // The label mask takes the node's background; the pinned icon box is always blue.
            $this->assertCount(1, \array_filter($marks[$node->name], static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && $node->fillColor === $mark->fill));
            $this->assertSame('#087ebf', $marks[$node->name][0]->fill);
        }
        $this->assertCount(3, \array_filter($marks['srv'], static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));
        $this->assertCount(4, \array_filter($marks['net'], static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind));
        $this->assertNotEquals($marks['db'][1]->points, $marks['disk'][1]->points);
        $this->assertCount(1, \array_filter($marks['missing'], static fn (Mark $mark): bool => '?' === $mark->text));
    }

    #[DataProvider('fixtures')]
    public function testGroupFramesContainTheirDescendantsAndHeadersKeepOrigins(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $diagram = self::parse($source);
        $scene = self::scene($source);
        $frames = \array_column($scene->graph->clusters, null, 'name');
        foreach ($scene->graph->nodes as $node) {
            $item = $diagram->items[$node->name];
            $this->assertSame($item['statement']->line, $scene->elements[$node->id]);
            $parent = $item['in'];
            while (null !== $parent) {
                $frame = $frames[$parent];
                $this->assertGreaterThanOrEqual($frame->x0, $node->x - ($node->w / 2));
                $this->assertLessThanOrEqual($frame->x1, $node->x + ($node->w / 2));
                $this->assertGreaterThanOrEqual($frame->y0, $node->y - ($node->h / 2));
                $this->assertLessThanOrEqual($frame->y1, $node->y + ($node->h / 2));
                $parent = $diagram->items[$parent]['in'];
            }
        }
        $this->assertNotEmpty($expected);
    }

    public function testRawTitlesRetainGrammarTextWhileGraphicsDecodeEntities(): void
    {
        $source = "architecture-beta\nservice a[\"One &amp; two\"]\naccTitle:  Short   title\naccDescr {\n  first   line\n\n   last line\n}";
        $diagram = self::parse($source);
        $this->assertSame('One &amp; two', $diagram->items['a']['title']);
        $this->assertSame('Short title', $diagram->metadata['accTitle']);
        $this->assertSame("first line\nlast line", $diagram->metadata['accDescr']);
        $this->assertSame('One & two', \array_first(self::scene($source)->graph->nodes)->label);
    }

    public function testGroupTraversalUsesFramePortsRatherThanServicePorts(): void
    {
        $scene = self::scene("architecture-beta\ngroup p\ngroup q\nservice a(server) in p\nservice b(server) in q\na{group}:R --> L:b{group}");
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $edge = $scene->graph->edges[0];
        $this->assertSame($frames['p']->x1, $edge->points[0][0]);
        $this->assertSame($frames['q']->x0, $edge->arrowTip[0]);
    }

    public function testInlineHeaderTitlesSplitIntoTheirOwnStatement(): void
    {
        $statements = \iterator_to_array(new ArchitectureLexer("architecture-beta title Arch\nservice db(database)[DB]")->statements(), preserve_keys: false);
        $this->assertSame(['architecture-beta', 'title Arch', 'service db(database)[DB]'], \array_column($statements, 'text'));
        $this->assertSame([0, 18, 29], \array_column($statements, 'offset'));
        $diagram = self::parse("architecture-beta accTitle: Short\nservice a(server)[A]");
        $this->assertSame('Short', $diagram->metadata['accTitle']);
        $scene = self::scene("architecture-beta title Arch\nservice db(database)[DB]");
        $this->assertSame(['text' => 'Arch', 'origin' => 0], $scene->legend[0]);
        $this->assertContains('DB', \array_column($scene->graph->nodes, 'label'));
    }
}
