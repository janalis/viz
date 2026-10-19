<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SequenceConfiguration;
use Janalis\Viz\Mermaid\SequenceYamlBlockKeys;

final class SequenceYamlBlockDepthTest extends TestCase
{
    #[DataProvider('depths')]
    public function testBlockKeyRangeScansHaveABoundedNestingBudget(int $depth, string $phase): void
    {
        $yaml = "? [entry]\n: Value";
        for ($level = 1; $level < $depth; ++$level) {
            $yaml = "?\n ".\str_replace("\n", replace: "\n ", subject: $yaml)."\n: Value";
        }
        $configuration = '{'."\n".$yaml."\nalias: Name\n}";
        $this->assertLessThanOrEqual(65_536, \strlen($configuration));
        if ($depth > 128) {
            $this->expectException(MermaidSyntaxError::class);
            $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        }
        try {
            if ('normalization' === $phase) {
                [$normalized, $marker] = SequenceYamlBlockKeys::normalize($yaml."\nalias: Name\n", new MermaidStatement('', offset: 0, line: 0, column: 0));
                $this->assertIsString($marker);
                $this->assertSame(1, \substr_count($normalized, needle: '[entry]'));

                return;
            }
            $result = SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 0, line: 0, column: 0));
        } catch (MermaidSyntaxError $error) {
            if ($depth > 128) {
                throw $error;
            }
            $this->fail('Block nesting within the documented budget was rejected: '.$error->getMessage());
        }
        $this->assertSame(['A', 'Name', null], $result);
    }

    public static function depths(): iterable
    {
        yield 'normalization exactly 128' => [128, 'normalization'];
        yield 'normalization one over' => [129, 'normalization'];
        yield 'parsing exactly 128' => [128, 'parsing'];
        yield 'parsing one over' => [129, 'parsing'];
    }
}
