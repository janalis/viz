<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Language;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotGraphBuilder;
use Janalis\Viz\Render\Work;

/**
 * The DOT grammar, by recursive descent: `[strict] (graph | digraph) [ID] { statements }`,
 * where a statement is a node, an edge chain whose operands may be subgraphs, an
 * attribute statement, an `ID = ID` assignment or a subgraph. Only the first graph of a
 * file is read; an edge operator must match the graph's kind.
 */
final class DotParser
{
    /** @var list<DotToken> */
    private array $tokens = [];
    private int $at = 0;
    private bool $directed = true;
    private DotGraphBuilder $builder;

    /**
     * @return \Generator<int, null, mixed, DotGraph>
     * @throws DotSyntaxError when the source is not DOT
     */
    public function parse(string $source): \Generator
    {
        $this->tokens = \iterator_to_array(new DotLexer($source)->tokens(), preserve_keys: false);
        $this->at = 0;
        $this->builder = new DotGraphBuilder();
        $strict = $this->keyword('strict');
        $kind = $this->peek();
        if (DotTokenKind::Keyword !== $kind->kind || !\in_array($kind->text, ['graph', 'digraph'], strict: true)) {
            throw self::error('Expected graph or digraph', $kind);
        }
        ++$this->at;
        $this->directed = 'digraph' === $kind->text;
        $name = DotTokenKind::Id === $this->peek()->kind ? $this->next()->text : '';
        $this->builder->begin($name, $this->directed, $strict);
        $this->expect(DotTokenKind::OpenBrace);
        $statements = $this->statements();
        yield from $statements;
        $this->expect(DotTokenKind::CloseBrace);

        return $this->builder->finish();
    }

    /** @return \Generator<int, null, mixed, void> */
    private function statements(): \Generator
    {
        while (!\in_array($this->peek()->kind, [DotTokenKind::CloseBrace, DotTokenKind::End], strict: true)) {
            $this->statement();
            if (DotTokenKind::Semicolon === $this->peek()->kind) {
                ++$this->at;
            }
            yield null;
        }
    }

    private function statement(): void
    {
        $token = $this->peek();
        if (DotTokenKind::Keyword === $token->kind && \in_array($token->text, ['graph', 'node', 'edge'], strict: true)) {
            ++$this->at;
            $this->builder->defaults($token->text, $this->required());

            return;
        }
        if (DotTokenKind::Id === $token->kind && DotTokenKind::Equals === ($this->tokens[$this->at + 1] ?? $token)->kind) {
            $this->at += 2;
            [$value, $html] = $this->value();
            $this->builder->defaults('graph', [[$token->text, $value, $html]]);

            return;
        }
        $operand = $this->operand();
        if (DotTokenKind::EdgeOp === $this->peek()->kind) {
            $this->chain($operand, $token->offset);

            return;
        }
        if (DotTokenKind::Id === $token->kind) {
            $this->builder->node($token->text, $token->offset, $this->attributes());
        }
    }

    /**
     * A node id with its port, or a subgraph, as the nodes it stands for.
     *
     * @return list<array{string, ?string}>
     */
    private function operand(): array
    {
        $token = $this->peek();
        if (DotTokenKind::OpenBrace === $token->kind || (DotTokenKind::Keyword === $token->kind && 'subgraph' === $token->text)) {
            return $this->subgraph();
        }
        if (DotTokenKind::Id !== $token->kind) {
            throw self::error('Expected a node, an edge, an attribute statement or a subgraph', $token);
        }
        ++$this->at;
        // A node exists from the moment it is read, before the rest of its statement.
        $this->builder->node($token->text, $token->offset);
        $port = null;
        if (DotTokenKind::Colon === $this->peek()->kind) {
            ++$this->at;
            $port = $this->id();
            if (DotTokenKind::Colon === $this->peek()->kind) {
                ++$this->at;
                $port .= ':'.$this->id();
            }
        }

        return [[$token->text, $port]];
    }

    /**
     * @param list<array{string, ?string}> $first
     */
    private function chain(array $first, int $offset): void
    {
        $operands = [$first];
        while (DotTokenKind::EdgeOp === $this->peek()->kind) {
            $operator = $this->next();
            if (('->' === $operator->text) !== $this->directed) {
                throw self::error($this->directed ? 'An undirected edge -- in a digraph' : 'A directed edge -> in an undirected graph', $operator);
            }
            $operands[] = $this->operand();
        }
        $attributes = $this->attributes();
        for ($index = 1, $count = \count($operands); $index < $count; ++$index) {
            $this->builder->edges($operands[$index - 1], $operands[$index], $offset, $attributes);
        }
    }

    /** @return list<array{string, ?string}> the nodes the subgraph holds, at any depth */
    private function subgraph(): array
    {
        $start = $this->peek();
        $name = null;
        if (DotTokenKind::Keyword === $start->kind) {
            ++$this->at;
            if (DotTokenKind::Id === $this->peek()->kind) {
                $name = $this->next()->text;
            }
        }
        $subgraph = $this->builder->open($name, $start->offset);
        if (DotTokenKind::OpenBrace !== $this->peek()->kind) {
            $this->builder->close();
            if (null === $name) {
                throw self::error('Expected {', $this->peek());
            }

            return \array_map(static fn (string $node): array => [$node, null], $this->builder->ordered($subgraph->nodes));
        }
        ++$this->at;
        Work::run($this->statements());
        $this->expect(DotTokenKind::CloseBrace);
        $this->builder->close();

        return \array_map(static fn (string $node): array => [$node, null], $this->builder->ordered($subgraph->nodes));
    }

    /**
     * The attribute lists an attribute statement must have.
     *
     * @return list<array{string, string, bool}>
     */
    private function required(): array
    {
        if (DotTokenKind::OpenBracket !== $this->peek()->kind) {
            throw self::error('Expected [', $this->peek());
        }

        return $this->attributes();
    }

    /**
     * Any `[name=value, …]` lists, run together.
     *
     * @return list<array{string, string, bool}>
     */
    private function attributes(): array
    {
        $attributes = [];
        while (DotTokenKind::OpenBracket === $this->peek()->kind) {
            ++$this->at;
            while (DotTokenKind::CloseBracket !== $this->peek()->kind) {
                $name = $this->id();
                $this->expect(DotTokenKind::Equals);
                [$value, $html] = $this->value();
                $attributes[] = [$name, $value, $html];
                if (\in_array($this->peek()->kind, [DotTokenKind::Comma, DotTokenKind::Semicolon], strict: true)) {
                    ++$this->at;
                }
            }
            ++$this->at;
        }

        return $attributes;
    }

    /** @return array{string, bool} the value, and whether it is an HTML string */
    private function value(): array
    {
        $token = $this->next();

        return match ($token->kind) {
            DotTokenKind::Id => [$token->text, false],
            DotTokenKind::Html => [$token->text, true],
            default => throw self::error('Expected a value', $token),
        };
    }

    private function id(): string
    {
        $token = $this->next();
        if (DotTokenKind::Id !== $token->kind) {
            throw self::error('Expected an identifier', $token);
        }

        return $token->text;
    }

    private function keyword(string $keyword): bool
    {
        $token = $this->peek();
        if (DotTokenKind::Keyword === $token->kind && $keyword === $token->text) {
            ++$this->at;

            return true;
        }

        return false;
    }

    private function expect(DotTokenKind $kind): void
    {
        $token = $this->next();
        if ($kind !== $token->kind) {
            throw self::error('Expected '.$kind->value, $token);
        }
    }

    private function peek(): DotToken
    {
        return $this->tokens[$this->at] ?? $this->tokens[\count($this->tokens) - 1];
    }

    private function next(): DotToken
    {
        $token = $this->peek();
        if (DotTokenKind::End !== $token->kind) {
            ++$this->at;
        }

        return $token;
    }

    private static function error(string $message, DotToken $where): DotSyntaxError
    {
        return new DotSyntaxError($message, $where->offset, $where->line, $where->column);
    }
}
