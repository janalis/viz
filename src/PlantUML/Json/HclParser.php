<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * HCL read as PlantUML's `HclParser` reads it, into the values a JSON diagram draws: its
 * lines run together, `#` lines left out; cut at spaces, brackets, `=`, `:` and `,`, a word
 * before `(` a function's name, `"…"` a string; each block a name, its words and quoted
 * strings, and `{ field = value … }`; one block the whole, several each under its name.
 */
final class HclParser
{
    /** @var list<array{string, string}> each token's kind and text */
    private array $terms = [];
    private int $at = 0;

    /**
     * @param list<string> $lines
     * @throws PlantUmlRefusal when PlantUML fails on it
     */
    public static function parse(array $lines): JsonNode
    {
        $parser = new self();
        $parser->tokenize(\implode('', \array_filter($lines, static fn (string $line): bool => !\str_starts_with(\trim($line), '#'))));
        /** @var list<array{string, JsonNode}> $modules */
        $modules = [];
        while ($parser->at < \count($parser->terms)) {
            [$name, $value] = $parser->module();
            // As a map: a name met again keeps its place, its block replaced.
            $index = \array_search($name, \array_column($modules, 0), strict: true);
            $modules[false === $index ? \count($modules) : $index] = [$name, $value];
        }

        return 1 === \count($modules) ? $modules[0][1] : JsonNode::object($modules);
    }

    /** @return array{string, JsonNode} a block's name, of its words and quoted strings, and its body */
    private function module(): array
    {
        $name = '';
        while (true) {
            [$kind, $text] = $this->next();
            match ($kind) {
                'quoted' => $name .= '"'.$text.'" ',
                'simple' => $name .= $text.' ',
                '{' => null,
                default => throw new PlantUmlRefusal('Unexpected '.$kind),
            };
            if ('{' === $kind) {
                return [\trim($name), $this->block()];
            }
        }
    }

    /** `field = value` or `field : value`, each in turn, up to `}`. */
    private function block(): JsonNode
    {
        $members = [];
        while (true) {
            [$kind, $text] = $this->next();
            if ('}' === $kind) {
                return JsonNode::object($members);
            }
            if ('simple' !== $kind && 'quoted' !== $kind) {
                throw new PlantUmlRefusal('Unexpected '.$kind);
            }
            [$separator] = $this->next();
            if ('=' !== $separator && ':' !== $separator) {
                throw new PlantUmlRefusal('Unexpected '.$separator);
            }
            $value = $this->value();
            if (!$value instanceof JsonNode) {
                throw new PlantUmlRefusal('Unexpected '.$value);
            }
            $members[] = [$text, $value];
        }
    }

    /** A value, or the `,` or `)` met instead, as its kind. */
    private function value(): JsonNode|string
    {
        [$kind, $text] = $this->next();

        return match ($kind) {
            ',', ')' => $kind,
            'quoted', 'simple' => JsonNode::string($text),
            '[' => $this->array(),
            '{' => $this->block(),
            'function' => $this->call($text),
            default => throw new PlantUmlRefusal('Unexpected '.$kind),
        };
    }

    /** As PlantUML's `getArray`: its blocks and quoted strings, anything else passed over. */
    private function array(): JsonNode
    {
        $items = [];
        while (true) {
            [$kind, $text] = $this->next();
            if ('{' === $kind) {
                $items[] = $this->block();
            }
            if (']' === $kind) {
                return JsonNode::array($items);
            }
            if ('quoted' === $kind) {
                $items[] = JsonNode::string($text);
            }
        }
    }

    /** `name(args)`: an object of `name()` and its arguments, or `name()` alone without any. */
    private function call(string $name): JsonNode
    {
        // Its `(`, which always follows a function's name.
        $this->next();
        $arguments = [];
        while (true) {
            $value = $this->value();
            if (')' === $value) {
                return [] === $arguments ? JsonNode::string($name.'()') : JsonNode::object([[$name.'()', JsonNode::array($arguments)]]);
            }
            if ($value instanceof JsonNode) {
                $arguments[] = $value;
            }
        }
    }

    /** @return array{string, string} */
    private function next(): array
    {
        return $this->terms[$this->at++] ?? throw new PlantUmlRefusal('The HCL ends too soon');
    }

    /** As PlantUML's `parse`: a word ends at any sign, before `(` a function's name. */
    private function tokenize(string $source): void
    {
        $pending = '';
        $characters = \preg_split('//u', $source, flags: \PREG_SPLIT_NO_EMPTY);
        $count = false === $characters ? 0 : \count($characters);
        for ($index = 0; $index < $count; ++$index) {
            $char = $characters[$index] ?? '';
            $kind = self::kind($char);
            if ('(' === $kind) {
                if ('' === $pending) {
                    throw new PlantUmlRefusal('A ( with no function');
                }
                $this->terms[] = ['function', $pending];
                $pending = '';
            }
            if (null !== $kind && '(' !== $kind && '' !== $pending) {
                $this->terms[] = ['simple', $pending];
                $pending = '';
            }
            if (' ' === $kind) {
                continue;
            }
            if (null !== $kind) {
                $this->terms[] = [$kind, ''];
                continue;
            }
            if ('"' !== $char) {
                $pending .= $char;
                continue;
            }
            $quoted = '';
            while (++$index < $count) {
                $char = $characters[$index] ?? '';
                if ('\\' === $char) {
                    $quoted .= $characters[++$index] ?? '';
                    continue;
                }
                if ('"' === $char) {
                    break;
                }
                $quoted .= $char;
            }
            $this->terms[] = ['quoted', $quoted];
        }
    }

    /** Each sign's kind, a space one of Unicode's, as Java's `isSpaceChar`; null for any other character. */
    private static function kind(string $char): ?string
    {
        if (1 === \preg_match('/^[\p{Zs}\p{Zl}\p{Zp}]$/u', $char)) {
            return ' ';
        }

        return \in_array($char, ['{', '}', '[', ']', '(', ')', '=', ',', ':'], strict: true) ? $char : null;
    }
}
