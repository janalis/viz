<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\NwDiag;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\NwDiag\NwNetwork;
use Janalis\Viz\PlantUML\NwDiag\NwReader;
use Janalis\Viz\PlantUML\NwDiag\NwServer;
use Janalis\Viz\PlantUML\NwDiag\PlantUmlNwDiag;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NwReaderTest extends TestCase
{
    public function testServersJoinTheNetworksTheyAreDeclaredIn(): void
    {
        $diagram = self::read(<<<'PUML'
            nwdiag {
              network dmz {
                address = "10.0.0.0/24"
                color = "pink"
                web [address = "10.0.0.1", description = "Web, front", shape = node];
              }
              network internal {
                width = full
                description = "Inside"
                web [address = "10.1.0.1"];
                db;
              }
            }
            PUML);

        $this->assertSame(['dmz', 'Inside'], \array_map(static fn (NwNetwork $network): string => $network->displayName(), $diagram->networks));
        $this->assertSame(['10.0.0.0/24', 'pink', false, true], [$diagram->networks[0]->address, $diagram->networks[0]->color, $diagram->networks[0]->fullWidth, $diagram->networks[1]->fullWidth]);
        [$web, $db] = $diagram->servers;
        $this->assertSame(['Web, front', 'node', '10.0.0.1', '10.1.0.1'], [$web->description, $web->shape, $web->address($diagram->networks[0]), $web->address($diagram->networks[1])]);
        $this->assertSame($diagram->networks[1], $db->main());
    }

    public function testServersOnNoNetworkJoinOneMadeUpAndNotDrawn(): void
    {
        $diagram = self::read("a;\nb [shape = cloud];");

        $this->assertCount(1, $diagram->networks);
        $this->assertFalse($diagram->networks[0]->visible);
        $this->assertSame([false, false], \array_map(static fn (NwServer $server): bool => $server->firstLinkShown, $diagram->servers));
    }

    public function testAPeerLinkGoesThroughAHiddenNetwork(): void
    {
        $diagram = self::read("inet [shape = cloud];\ninet -- router;\nnetwork {\n  router;\n  web;\n}");

        $this->assertSame([false, false, true], \array_map(static fn (NwNetwork $network): bool => $network->visible, $diagram->networks));
        $this->assertSame(['inet', 'router', 'web'], \array_map(static fn (NwServer $server): string => $server->name, $diagram->servers));
        $this->assertSame($diagram->networks[1], $diagram->servers[1]->main());
    }

    public function testServersDeclaredBeforeTheFirstNetworkJoinOneMadeUpAboveIt(): void
    {
        $diagram = self::read("a;\nb;\nnetwork n {\n  c;\n}");

        $this->assertSame([false, true], \array_map(static fn (NwNetwork $network): bool => $network->visible, $diagram->networks));
        $this->assertSame([$diagram->networks[0], $diagram->networks[0], $diagram->networks[1]], \array_map(static fn (NwServer $server): ?NwNetwork => $server->main(), $diagram->servers));
    }

    public function testALinkFromAServerNotDeclaredMakesItsNetwork(): void
    {
        $diagram = self::read('a -- b');

        // As PlantUML: the network named after the first, the second on it.
        $this->assertSame(['a'], \array_map(static fn (NwNetwork $network): string => $network->displayName(), $diagram->networks));
        $this->assertSame(['b'], \array_map(static fn (NwServer $server): string => $server->name, $diagram->servers));
    }

    public function testALinkBetweenTwoDeclaredServersGoesThroughAHiddenNetworkTheModelJoinsThemAcross(): void
    {
        $diagram = self::read("a;\nb;\na -- b");
        $graph = $diagram->model();

        $this->assertSame([false, false], \array_map(static fn (NwNetwork $network): bool => $network->visible, $diagram->networks));
        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['a', 'b'], \array_map(static fn (Node $node): ?string => $node->label, $graph->nodes), 'no node for a network not drawn');
        $this->assertSame([['n0', 'n1']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to], $graph->edges), 'the servers on it joined to each other');
    }

    public function testAServerDescribedOutsideANetworkLearnsItsAddressOnTheFirstWithNone(): void
    {
        $diagram = self::read("network n {\n  a [address = \"1\"];\n  a;\n}\nnetwork m {\n  a;\n}\na [address = \"2\"];");
        [$n, $m] = $diagram->networks;

        $this->assertSame(['1', '2'], [$diagram->servers[0]->address($n), $diagram->servers[0]->address($m)]);
    }

    public function testANetworkMadeUpIsNamedWithASpaceAndOneWithNoNameTakesNoLine(): void
    {
        $size = static function (string $lines): array {
            $drawing = Work::run(self::read($lines)->drawing());

            return [$drawing->width / Units::POINT, $drawing->height / Units::POINT];
        };

        // As the jar sizes them: a network made up names itself with a no-break space, as wide as a space; a network of no name has no line for one.
        $this->assertSame([109.0, 120.0], $size('web01;'));
        $this->assertSame([105.0, 113.0], $size("network {\n  web01;\n}"));
        $this->assertSame([113.0, 120.0], $size("network n {\n  web01;\n}"));
    }

    public function testWhatHoldsNothingDrawsNothing(): void
    {
        $polygons = static fn (string $lines): int => \count(\array_filter(Work::run(self::read($lines)->drawing())->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind));

        // An empty group is not shaded, a colour outside any block goes nowhere, a network with no server is its bar alone.
        $this->assertSame($polygons("network n {\n  a;\n}"), $polygons("color = \"red\"\ngroup {\n}\nnetwork n {\n  a;\n}"));
        $this->assertSame(1, $polygons("network n {\n}"));
        // A shape PlantUML does not know is a rectangle.
        $this->assertSame($polygons('a;'), $polygons('a [shape = foo];'));
    }

    public function testADiagramWiderThanAHundredColumnsIsRefusedAsPlantUmlRefusesIt(): void
    {
        $this->expectException(PlantUmlRefusal::class);
        $this->expectExceptionMessage('The network diagram is too wide');

        Work::run(self::read("network n {\n".\implode("\n", \array_map(static fn (int $index): string => 's'.$index.';', \range(start: 0, end: 101)))."\n}")->drawing());
    }

    public function testGroupsShadeTheirServersWithTheirDescriptionAbove(): void
    {
        $drawing = Work::run(self::read("group {\n  color = \"#FFAAAA\"\n  description = \"Web\"\n  a;\n  b;\n}\nnetwork n {\n  a;\n  b;\n}")->drawing());
        $shaded = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind && '#ffaaaa' === $mark->fill));
        $texts = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind && 'Web' === $mark->text));

        $this->assertCount(1, $shaded);
        $this->assertCount(1, $texts);
        $this->assertLessThan(\max(\array_column($shaded[0]->points, 1)) / Units::POINT, $texts[0]->points[0][1] / Units::POINT);
    }

    public function testALineHopsOverTheNetworksItCrosses(): void
    {
        $drawing = Work::run(self::read("network A {\n  gw;\n  a;\n}\nnetwork B {\n  width = full\n  a;\n}\nnetwork C {\n  gw;\n}")->drawing());
        $lines = \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind && \count($mark->points) > 2));

        // gw's line down to C, across the whole width of B, bends out round it.
        $this->assertCount(1, $lines);
        $this->assertGreaterThan(\min(\array_column($lines[0]->points, 0)) + (5 * Units::POINT), \max(\array_column($lines[0]->points, 0)));
    }

    public function testTheAttributesAreReadAsPlantUmlReadsThem(): void
    {
        $this->assertSame(['address' => '1.2.3.4', 'shape' => 'node', 'description' => 'a, b'], NwReader::attributes('address = "1.2.3.4", shape=node, description = "a, b"'));
    }

    public function testTheModelJoinsServersToTheirNetworks(): void
    {
        $graph = self::read("network n {\n  a [address = \"1\"];\n}")->model();

        $this->assertInstanceOf(Graph::class, $graph);
        $this->assertSame(['n', 'a'], \array_map(static fn (Node $node): ?string => $node->label, $graph->nodes));
        $this->assertSame([['net0', 'n0', '1']], \array_map(static fn (Edge $edge): array => [$edge->from, $edge->to, $edge->label], $graph->edges));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'nested groups' => ["group {\ngroup {", 'Cannot nest group'];
        yield 'a network in a group' => ["group {\nnetwork n {", 'Cannot open network in a group'];
        yield 'nested networks' => ["network a {\nnetwork b {", 'Cannot nest network'];
        yield 'a server in two groups' => ["group {\na;\n}\ngroup {\na;\n}", 'Element already in another group.'];
        yield 'a link from nowhere' => ["network n {\na;\n}\nb -- c", 'what about b'];
        yield 'a shape drawn by PlantUML' => ['a [shape = actor];', 'shape actor'];
        yield 'a description outside any network' => ['description = x', 'A description outside any network'];
        yield 'a command left to PlantUML' => ["hide empty description\na;", 'does not draw: hide empty description'];
    }

    #[DataProvider('failures')]
    public function testItRefusesWhatPlantUmlFailsOn(string $lines, string $message): void
    {
        $this->expectException(Unsupported::class);
        $this->expectExceptionMessage($message);

        Work::run(PlantUmlPipeline::native()->drawing("@startnwdiag\n".$lines."\n@endnwdiag"));
    }

    private static function read(string $lines): PlantUmlNwDiag
    {
        $diagram = PlantUmlPipeline::native()->diagram("@startnwdiag\n".$lines."\n@endnwdiag");
        self::assertInstanceOf(PlantUmlNwDiag::class, $diagram);

        return $diagram;
    }
}
