<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * YAML read as PlantUML's `YamlParser` and `YamlBuilder` read it, line by line: keys and
 * values, a key alone opening a map — or taking the text indented under it, run together,
 * as its value — `|` taking the lines indented under it; `- ` items, `-` alone opening one;
 * `[…]` flow sequences. What it fails on, `>` among it, it refuses.
 */
final class YamlParser
{
    /** @var list<int> */
    private array $indents = [];
    /** @var list<YamlValue> the values being filled, the root first */
    private array $nodes;
    private int $at = 0;

    /** @param list<string> $lines */
    private function __construct(
        private readonly array $lines,
    ) {
        $this->nodes = [new YamlValue()];
    }

    /**
     * @param list<string> $lines
     * @throws PlantUmlRefusal where PlantUML's parser fails, which its diagram then says
     */
    public static function parse(array $lines): JsonNode
    {
        $parser = new self($lines);
        for ($count = \count($lines); $parser->at < $count; ++$parser->at) {
            $parser->line(YamlLine::of($lines[$parser->at]));
        }

        return $parser->nodes[0]->json() ?? throw new PlantUmlRefusal('Your data does not sound like YAML data');
    }

    private function line(YamlLine $line): void
    {
        if (YamlLineType::Empty === $line->type) {
            return;
        }
        if (YamlLineType::Text === $line->type) {
            throw new PlantUmlRefusal('YamlLineType.NO_KEY_ONLY_TEXT');
        }
        if (YamlLineType::PlainDash === $line->type) {
            $this->opened();

            return;
        }
        $this->indent($line->indent);
        $key = (string) $line->key;
        if ($line->item) {
            match ($line->type) {
                YamlLineType::KeyOnly => $this->open($this->opened(), $key),
                YamlLineType::ListElement => $this->item(YamlValue::scalar((string) $line->value)),
                YamlLineType::KeyAndValue => $this->opened()->put($key, YamlValue::scalar((string) $line->value)),
                YamlLineType::FlowSequence => $this->opened()->put($key, YamlValue::list($line->values)),
                default => throw new PlantUmlRefusal('wip3'),
            };

            return;
        }
        match ($line->type) {
            YamlLineType::KeyOnly => $this->keyOnly($line),
            YamlLineType::KeyAndValue => $this->last()->put($key, YamlValue::scalar((string) $line->value)),
            YamlLineType::BlockStyle => $this->last()->put($key, YamlValue::scalar($this->block($line->indent))),
            YamlLineType::FlowSequence => $this->last()->put($key, YamlValue::list($line->values)),
            default => throw new PlantUmlRefusal('wip4'),
        };
    }

    /** A key alone: with nothing under it, an empty value; with text under it, that text; else a map. */
    private function keyOnly(YamlLine $line): void
    {
        $key = (string) $line->key;
        $next = $this->next();
        if (null === $next || $next->indent <= $line->indent) {
            $this->last()->put($key, YamlValue::scalar(''));

            return;
        }
        if (YamlLineType::Text === $next->type) {
            $this->last()->put($key, YamlValue::scalar($this->text()));

            return;
        }
        $this->open($this->last(), $key);
    }

    /** As PlantUML's `adjustIndentation`: deeper, one more level; shallower, the values it closes left, and a list they stood in. */
    private function indent(int $indent): void
    {
        if ([] === $this->indents || $indent > $this->indents[\count($this->indents) - 1]) {
            $this->indents[] = $indent;

            return;
        }
        while ([] !== $this->indents && $indent < $this->indents[\count($this->indents) - 1]) {
            \array_pop($this->indents);
            $this->pop();
            if ($this->last()->isList()) {
                $this->pop();
            }
        }
    }

    /** An item of the list being filled, the item before it closed when it was opened. */
    private function item(YamlValue $item): void
    {
        $count = \count($this->nodes);
        if ($count >= 2 && $this->nodes[$count - 2]->isList() && $this->nodes[$count - 2]->last() === $this->nodes[$count - 1]) {
            $this->pop();
        }
        $this->last()->add($item);
    }

    /** An item that may hold keys, opened to be filled. */
    private function opened(): YamlValue
    {
        $item = new YamlValue();
        $this->item($item);
        $this->nodes[] = $item;

        return $item;
    }

    private function open(YamlValue $holder, string $key): void
    {
        $value = new YamlValue();
        $holder->put($key, $value);
        $this->nodes[] = $value;
    }

    private function last(): YamlValue
    {
        return $this->nodes[\count($this->nodes) - 1] ?? throw new PlantUmlRefusal('Bad YAML indentation');
    }

    private function pop(): void
    {
        if ([] === $this->nodes) {
            throw new PlantUmlRefusal('Bad YAML indentation');
        }
        \array_pop($this->nodes);
    }

    /** The next line that is not empty. */
    private function next(): ?YamlLine
    {
        for ($at = $this->at + 1, $count = \count($this->lines); $at < $count; ++$at) {
            $line = YamlLine::of($this->lines[$at]);
            if (YamlLineType::Empty !== $line->type) {
                return $line;
            }
        }

        return null;
    }

    /** The text on the lines after, run together with spaces, up to one that is neither text nor empty. */
    private function text(): string
    {
        $parts = [];
        for ($count = \count($this->lines); ($this->at + 1) < $count; ++$this->at) {
            $line = YamlLine::of($this->lines[$this->at + 1]);
            if (YamlLineType::Empty === $line->type) {
                continue;
            }
            if (YamlLineType::Text !== $line->type) {
                break;
            }
            $parts[] = (string) $line->value;
        }

        return \implode(' ', $parts);
    }

    /** A `|` value: the lines after, each trimmed and ended, up to one with a key as shallow as its own. */
    private function block(int $indent): string
    {
        $block = '';
        for ($count = \count($this->lines); ($this->at + 1) < $count; ++$this->at) {
            $raw = $this->lines[$this->at + 1];
            $line = YamlLine::of($raw);
            if (YamlLineType::Text !== $line->type && YamlLineType::Empty !== $line->type && $line->indent <= $indent) {
                break;
            }
            $block .= \trim($raw)."\n";
        }

        return $block;
    }
}
