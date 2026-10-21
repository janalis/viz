<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * The lines of a body as PlantUML's chain of code iterators gives them to its preprocessor,
 * each layer acting on what the one under it gives, from the innermost: comments dropped,
 * `!startsub` blocks kept and read on, the bodies of functions and procedures being declared
 * taken, `!if`s and what they leave out, `!define`s, `!while` and `!foreach` loops, and
 * affectations — what is left is for the preprocessor to carry out.
 */
final class TimLines
{
    private int $at = 0;
    private int $jumps = 0;
    /** @var ?array{list<string>, int} the lines of a `!startsub` block being read, and where */
    private ?array $sub = null;

    /** @param list<string> $lines */
    public function __construct(
        private readonly array $lines,
        private readonly TimContext $context,
        private readonly TimMemory $memory,
    ) {}

    public function peek(): ?string
    {
        while (null !== ($line = $this->peekForeach())) {
            if (TimLineType::Affectation !== TimLineType::of($line)) {
                return $line;
            }
            $this->affect($line);
            $this->next();
        }

        return null;
    }

    /** Past the line given last: in a `!startsub` block being read, or in the body. */
    public function next(): void
    {
        if (null === $this->sub) {
            ++$this->at;

            return;
        }
        ++$this->sub[1];
        if (!\array_key_exists($this->sub[1], $this->sub[0])) {
            $this->sub = null;
        }
    }

    /** An affectation, as PlantUML's: JSON going on past the line takes the lines after it. */
    private function affect(string $line): void
    {
        $last = -1;
        while (true) {
            try {
                $this->context->affect($this->memory, $line);

                return;
            } catch (JsonError $error) {
                if ($error->offset <= $last) {
                    throw new TimError('Error in JSON format', $line);
                }
                $last = $error->offset;
                $this->next();
                $line .= $this->peekForeach() ?? throw new TimError('Fatal parsing error', $line);
            }
        }
    }

    private function peekForeach(): ?string
    {
        $level = 0;
        while (null !== ($line = $this->peekWhile())) {
            $type = TimLineType::of($line);
            $loop = \array_key_last($this->memory->foreachs);
            if (null !== $loop && $this->memory->foreachs[$loop][4]) {
                $level += match ($type) {
                    TimLineType::Foreach => 1,
                    TimLineType::Endforeach => -1,
                    default => 0,
                };
                if (-1 === $level) {
                    \array_pop($this->memory->foreachs);
                    $level = 0;
                }
                $this->next();
                continue;
            }
            if (TimLineType::Foreach !== $type && TimLineType::Endforeach !== $type) {
                return $line;
            }
            TimLineType::Foreach === $type ? $this->startForeach(TimEater::trimmed($line)) : $this->endForeach($loop, $line);
            $this->next();
        }

        return null;
    }

    private function startForeach(string $line): void
    {
        $eater = new TimEater($line);
        $eater->skipSpaces();
        $eater->expect('!foreach');
        $eater->skipSpaces();
        $name = $eater->name();
        $eater->skipSpaces();
        $eater->expect('in');
        $eater->skipSpaces();
        $json = $this->context->expression($this->memory, $eater)->json();
        if (null !== $json && !$json->isArray() && !$json->isObject()) {
            throw new TimError('Fatal parsing error', $line);
        }
        $skip = null === $json || 0 === $json->size();
        $this->memory->foreachs[] = [$name, $json, $this->at, 0, $skip];
        if (!$skip) {
            $this->loopVariable(\count($this->memory->foreachs) - 1);
        }
    }

    private function endForeach(?int $loop, string $line): void
    {
        if (null === $loop) {
            throw new TimError('No foreach related to this endforeach', $line);
        }
        $index = ++$this->memory->foreachs[$loop][3];
        if ($index >= ($this->memory->foreachs[$loop][1]?->size() ?? 0)) {
            \array_pop($this->memory->foreachs);

            return;
        }
        $this->loopVariable($loop);
        $this->jump($this->memory->foreachs[$loop][2], $line);
    }

    /** The loop's variable set, globally, to the item it is at — for an object, the name. */
    private function loopVariable(int $loop): void
    {
        [$name, $json, , $index] = $this->memory->foreachs[$loop];
        $value = null !== $json && $json->isObject() ? JsonValue::string($json->names()[$index]) : $json?->get($index);
        $this->memory->put($name, TimValue::ofJson($value ?? JsonValue::literal('null')), global: true);
    }

    private function peekWhile(): ?string
    {
        $level = 0;
        while (null !== ($line = $this->peekDefine())) {
            $type = TimLineType::of($line);
            $loop = \array_key_last($this->memory->whiles);
            if (null !== $loop && $this->memory->whiles[$loop][2]) {
                $level += match ($type) {
                    TimLineType::While => 1,
                    TimLineType::Endwhile => -1,
                    default => 0,
                };
                if (-1 === $level) {
                    \array_pop($this->memory->whiles);
                    $level = 0;
                }
                $this->next();
                continue;
            }
            if (TimLineType::While !== $type && TimLineType::Endwhile !== $type) {
                return $line;
            }
            TimLineType::While === $type ? $this->startWhile(TimEater::trimmed($line)) : $this->endWhile($loop, $line);
            $this->next();
        }

        return null;
    }

    private function startWhile(string $line): void
    {
        $eater = new TimEater($line);
        $eater->skipSpaces();
        $eater->expect('!while');
        $eater->skipSpaces();
        $tokens = $eater->tokens();
        if ([] === $tokens) {
            throw new TimError('Missing expression', $line);
        }
        $holds = TimExpression::evaluate($tokens, $this->context, $this->memory)->toBool();
        $this->memory->whiles[] = [$tokens, $this->at, !$holds];
    }

    private function endWhile(?int $loop, string $line): void
    {
        if (null === $loop) {
            throw new TimError('No while related to this endwhile', $line);
        }
        if (TimExpression::evaluate($this->memory->whiles[$loop][0], $this->context, $this->memory)->toBool()) {
            $this->jump($this->memory->whiles[$loop][1], $line);

            return;
        }
        \array_pop($this->memory->whiles);
    }

    /** Back to where a loop starts, as PlantUML's: in the body, a thousand times at most. */
    private function jump(int $to, string $line): void
    {
        if (++$this->jumps > 999) {
            throw new TimError('Infinite loop?', $line);
        }
        $this->at = $to;
    }

    private function peekDefine(): ?string
    {
        while (null !== ($line = $this->peekIf())) {
            $type = TimLineType::of($line);
            if (TimLineType::LegacyDefine !== $type && TimLineType::LegacyDefinelong !== $type) {
                return $line;
            }
            $this->context->legacyDefine($this->memory, $line, $type);
            $this->next();
        }

        return null;
    }

    private function peekIf(): ?string
    {
        while (null !== ($line = $this->peekProcedure())) {
            $type = TimLineType::of($line);
            if (\in_array($type, [TimLineType::If, TimLineType::Ifdef, TimLineType::Ifndef, TimLineType::Else, TimLineType::Elseif, TimLineType::Endif], strict: true)) {
                $this->context->conditional($this->memory, $line, $type);
                $this->next();
                continue;
            }
            if ([] !== $this->memory->ifs && !$this->memory->allIfsHold()) {
                $this->next();
                continue;
            }

            return $line;
        }

        return null;
    }

    /**
     * The bodies of functions and procedures being declared taken, and their declarations: a
     * function's first, as PlantUML's inner layer takes them, then a procedure's.
     */
    private function peekProcedure(): ?string
    {
        while (null !== ($line = $this->peekSub())) {
            $type = TimLineType::of($line);
            if (
                !$this->took($line, $type, [TimFunctionType::ReturnFunction], TimLineType::DeclareReturnFunction)
                && !$this->took($line, $type, [TimFunctionType::Procedure, TimFunctionType::LegacyDefinelong], TimLineType::DeclareProcedure)
            ) {
                return $line;
            }
            $this->next();
        }

        return null;
    }

    /**
     * Whether a line goes into the body of a function of these types being declared, ends it,
     * or declares one.
     *
     * @param list<TimFunctionType> $types
     */
    private function took(string $line, TimLineType $type, array $types, TimLineType $declaration): bool
    {
        $pending = $this->context->functions->pending;
        if (null !== $pending && \in_array($pending->type(), $types, strict: true)) {
            if (TimLineType::EndFunction !== $type) {
                $pending->add($line);

                return true;
            }
            if (TimFunctionType::ReturnFunction === $pending->type() && !$pending->returns()) {
                throw new TimError('This function does not have any !return directive. Declare it as a procedure instead ?', $line);
            }
            $this->context->functions->end($pending);

            return true;
        }
        if ($declaration !== $type) {
            return false;
        }
        $this->context->declare($this->memory, $line, $type);

        return true;
    }

    /** A `!startsub` block kept under its name, then read on as the lines after it. */
    private function peekSub(): ?string
    {
        if (null !== $this->sub) {
            return $this->sub[0][$this->sub[1]] ?? null;
        }
        $line = $this->peekComment();
        if (null === $line || TimLineType::Startsub !== TimLineType::of($line)) {
            return $line;
        }
        $name = $this->context->subName($line);
        ++$this->at;
        $body = [];
        while (null !== ($inner = $this->peekComment())) {
            $type = TimLineType::of($inner);
            ++$this->at;
            if (TimLineType::Startsub === $type) {
                throw new TimError('Cannot nest sub', $line);
            }
            if (TimLineType::Endsub === $type) {
                $this->context->subs[$name] = $body;
                $this->sub = [$body, 0];

                return $body[0] ?? null;
            }
            $body[] = $inner;
        }
        $this->context->subs[$name] = $body;

        return $line;
    }

    /** The line here without its comments: none for a comment, a long comment's lines skipped. */
    private function peekComment(): ?string
    {
        while (\array_key_exists($this->at, $this->lines)) {
            $line = $this->lines[$this->at];
            $type = TimLineType::of($line);
            if (TimLineType::CommentLongStart === $type) {
                $this->skipLongComment();
                continue;
            }
            if (TimLineType::CommentSimple === $type) {
                ++$this->at;
                continue;
            }

            return self::withoutInnerComment($line);
        }

        return null;
    }

    private function skipLongComment(): void
    {
        while (\array_key_exists($this->at, $this->lines) && !\str_ends_with(TimEater::trimmed($this->lines[$this->at]), "'/")) {
            ++$this->at;
        }
        if (\array_key_exists($this->at, $this->lines)) {
            ++$this->at;
        }
    }

    /** As PlantUML's `removeInnerComment`: a comment opening or closing the line, and `/'''…'''/` within it. */
    private static function withoutInnerComment(string $line): string
    {
        $trimmed = \trim(\str_replace("\t", replace: ' ', subject: $line));
        $close = \strpos($line, needle: "'/");
        if (\str_starts_with($trimmed, "/'") && false !== $close) {
            return self::withoutSpecial(\substr($line, $close + 2));
        }
        $open = \strrpos($line, needle: "/'");
        if (\str_ends_with($trimmed, "'/") && false !== $open) {
            return self::withoutSpecial(\substr($line, offset: 0, length: $open));
        }
        if (\str_contains($trimmed, "/'''") && \str_contains($trimmed, "'''/")) {
            return self::withoutSpecial($line);
        }

        return $line;
    }

    private static function withoutSpecial(string $line): string
    {
        if (!\str_contains($line, "/'''") || !\str_contains($line, "'''/")) {
            return $line;
        }

        return (string) \preg_replace("~/'''[-\\w]*'''/~", replacement: '', subject: $line);
    }
}
