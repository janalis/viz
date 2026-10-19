<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SequenceConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SequenceAliasOverrideTest extends TestCase
{
    #[DataProvider('overrides')]
    public function testExplicitDescriptionsOverrideUnrenderableAliasesWithoutLosingTypesOrOrigins(string $keyword, string $configuration, string $description, string $expected): void
    {
        $source = "sequenceDiagram\n".$keyword.' A@'.$configuration.' as '.$description."\n";
        if (\str_starts_with($keyword, 'create')) {
            $source .= "B->>A: created\n";
        }
        $scene = NativeFixtureScene::render($source.'A->>B: after');
        $actor = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $actor);
        $this->assertSame($expected, $actor->label);
        $this->assertSame(1, $scene->origin($actor->id));
        $this->assertContains('A: '.$expected.' [boundary]', \array_column($scene->legend, column_key: 'text'));
    }

    public static function overrides(): iterable
    {
        foreach (self::configurations() as $name => $configuration) {
            foreach (['Visible' => 'Visible', 'wrap: Visible' => 'Visible', 'nowrap: Visible' => 'Visible', '#65;' => 'A'] as $description => $expected) {
                foreach (['actor', 'participant', 'create actor', 'create participant'] as $keyword) {
                    yield $name.' '.$description.' '.$keyword => [$keyword, $configuration, $description, $expected];
                }
            }
        }
    }

    /** @return array<string, string> */
    private static function configurations(): array
    {
        return [
            'JSON number' => '{"alias":1,"type":"boundary"}',
            'YAML number' => '{alias: 1, type: boundary}',
            'boolean' => '{alias: true, type: boundary}',
            'array' => '{alias: [Entry], type: boundary}',
            'map' => "{\ntype: boundary\nalias:\n field: Value\n}",
            'cyclic map' => "{\ntype: boundary\nalias: &self\n field: *self\n}",
        ];
    }

    #[DataProvider('selectedAliases')]
    public function testDescriptionsSpellingTheParticipantIdStillSelectAndRejectNonStringAliases(string $configuration, string $description): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unsupported sequence participant configuration field');
        SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 0, line: 1, column: 2), $description);
    }

    public static function selectedAliases(): iterable
    {
        foreach (self::configurations() as $name => $configuration) {
            foreach (['A', 'wrap: A', 'nowrap: A'] as $description) {
                yield $name.' '.$description => [$configuration, $description];
            }
        }
    }

    #[DataProvider('invalid')]
    public function testIgnoredAliasesStillValidateTheirAuthoredYaml(string $configuration): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 0, line: 1, column: 2), description: 'Visible');
    }

    public static function invalid(): iterable
    {
        yield 'unknown tag' => ['{alias: !!binary aGk=}'];
        yield 'undefined reference' => ['{alias: *missing}'];
        yield 'unfinished collection' => ['{alias: [Entry}'];
        yield 'duplicate field' => ['{alias: null, alias: true}'];
    }
}
