<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * An expression of the preprocessor evaluated as PlantUML's `TokenStack` does: a name before
 * parentheses taken for a call, the tokens put in reverse Polish order by its shunting yard
 * — a plain word a variable, or the word itself — and the result worked out.
 */
final class TimExpression
{
    /** @param list<TimToken> $symbols */
    public static function evaluate(array $symbols, TimContext $context, TimMemory $memory): TimValue
    {
        $symbols = \array_values(\array_filter($symbols, static fn (TimToken $symbol): bool => TimTokenType::Spaces !== $symbol->type));

        return self::reversePolish(self::shunted(self::calls($symbols), $context, $memory), $context, $memory);
    }

    /**
     * The calls among the tokens: each plain word before a `(`, its parentheses marked with its
     * number of arguments, in the order PlantUML's hash map of them goes.
     *
     * @param list<TimToken> $symbols
     * @return list<TimToken>
     */
    private static function calls(array $symbols): array
    {
        /** @var list<int> $open */
        $open = [];
        /** @var array<int, int> $pairs */
        $pairs = [];
        foreach ($symbols as $index => $symbol) {
            if (TimTokenType::OpenParenMath === $symbol->type) {
                $open[] = $index;
                continue;
            }
            if (TimTokenType::CloseParenMath === $symbol->type) {
                $pairs[\array_pop($open) ?? throw new TimError('Fatal parsing error')] = $index;
            }
        }
        foreach (self::hashOrder($pairs) as $start => $end) {
            if (0 === $start || TimTokenType::PlainText !== $symbols[$start - 1]->type) {
                continue;
            }
            $symbols[$start - 1] = new TimToken($symbols[$start - 1]->surface, TimTokenType::FunctionName);
            $symbols[$start] = new TimToken((string) self::countArguments($symbols, $start + 1), TimTokenType::OpenParenFunc);
            $symbols[$end] = new TimToken(')', TimTokenType::CloseParenFunc);
        }

        return $symbols;
    }

    /**
     * Pairs in the order Java's `HashMap` of small integers holds them: by their key's bucket
     * in a table that doubles past three quarters full, then as put.
     *
     * @param array<int, int> $pairs
     * @return array<int, int>
     */
    private static function hashOrder(array $pairs): array
    {
        $capacity = 16;
        while (\count($pairs) > ($capacity * 0.75)) {
            $capacity *= 2;
        }
        $keys = \array_keys($pairs);
        $places = \array_flip($keys);
        \usort($keys, static fn (int $a, int $b): int => [$a & ($capacity - 1), $places[$a]] <=> [$b & ($capacity - 1), $places[$b]]);
        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $pairs[$key];
        }

        return $ordered;
    }

    /**
     * As PlantUML counts a call's arguments: commas outside parentheses up to its end — or up
     * to the end of a call already marked within it.
     *
     * @param list<TimToken> $symbols
     */
    private static function countArguments(array $symbols, int $at): int
    {
        $first = \array_key_exists($at, $symbols) ? $symbols[$at]->type : null;
        if (TimTokenType::CloseParenMath === $first || TimTokenType::CloseParenFunc === $first) {
            return 0;
        }
        $count = 1;
        $level = 0;
        // The call's own closing parenthesis ends the count before the tokens do.
        while (true) {
            $type = $symbols[$at++]->type;
            if (TimTokenType::CloseParenFunc === $type || (0 === $level && TimTokenType::CloseParenMath === $type)) {
                return $count;
            }
            if (0 === $level && TimTokenType::Comma === $type) {
                ++$count;
                continue;
            }
            $level += match ($type) {
                TimTokenType::OpenParenMath, TimTokenType::OpenParenFunc => 1,
                TimTokenType::CloseParenMath => -1,
                default => 0,
            };
        }
    }

    /**
     * @param list<TimToken> $symbols
     * @return list<TimToken>
     */
    private static function shunted(array $symbols, TimContext $context, TimMemory $memory): array
    {
        $output = [];
        $operators = [];
        foreach ($symbols as $symbol) {
            match ($symbol->type) {
                TimTokenType::Number, TimTokenType::QuotedString, TimTokenType::JsonData => $output[] = $symbol,
                TimTokenType::Spaces => null,
                TimTokenType::FunctionName, TimTokenType::OpenParenFunc, TimTokenType::OpenParenMath => $operators[] = $symbol,
                TimTokenType::PlainText => $output[] = self::word($symbol->surface, $context, $memory),
                TimTokenType::Operator, TimTokenType::Affectation => self::operator($symbol, $operators, $output),
                TimTokenType::CloseParenFunc, TimTokenType::Comma => self::popUntil(TimTokenType::OpenParenFunc, $operators, $output, $symbol),
                TimTokenType::CloseParenMath => self::popUntil(TimTokenType::OpenParenMath, $operators, $output, $symbol),
            };
        }

        return [...$output, ...\array_reverse($operators)];
    }

    /** A plain word: the variable of its name, or the word itself when it could be one. */
    private static function word(string $name, TimContext $context, TimMemory $memory): TimToken
    {
        $variable = $context->variable($memory, $name);
        if (null !== $variable) {
            return $variable->token();
        }
        if (1 !== \preg_match('/^[a-zA-Z0-9.$_]+$/', $name)) {
            throw new TimError('Parsing syntax error about '.$name);
        }

        return new TimToken($name, TimTokenType::QuotedString);
    }

    /**
     * @param list<TimToken> $operators
     * @param list<TimToken> $output
     */
    private static function operator(TimToken $symbol, array &$operators, array &$output): void
    {
        while ([] !== $operators) {
            $top = $operators[\count($operators) - 1];
            if (TimTokenType::FunctionName !== $top->type && (!$top->isOperatorOrAffectation() || $top->precedence() < $symbol->precedence())) {
                break;
            }
            $output[] = \array_pop($operators);
        }
        $operators[] = $symbol;
    }

    /**
     * Operators to the output up to the parenthesis that opens, which goes there too after a
     * call, and goes away after a sum; a comma leaves it where it is.
     *
     * @param list<TimToken> $operators
     * @param list<TimToken> $output
     */
    private static function popUntil(TimTokenType $open, array &$operators, array &$output, TimToken $symbol): void
    {
        while ([] !== $operators && $operators[\count($operators) - 1]->type !== $open) {
            $output[] = \array_pop($operators);
        }
        if (TimTokenType::Comma === $symbol->type) {
            return;
        }
        $first = \array_pop($operators) ?? throw new TimError('Fatal parsing error');
        if (TimTokenType::CloseParenFunc === $symbol->type) {
            $output[] = $first;
        }
    }

    /** @param list<TimToken> $queue */
    private static function reversePolish(array $queue, TimContext $context, TimMemory $memory): TimValue
    {
        $named = [];
        $stack = [];
        for ($at = 0, $count = \count($queue); $at < $count; ++$at) {
            $symbol = $queue[$at];
            match ($symbol->type) {
                TimTokenType::Number => $stack[] = TimValue::number($symbol->surface),
                TimTokenType::QuotedString => $stack[] = TimValue::string($symbol->surface),
                TimTokenType::JsonData => $stack[] = TimValue::ofJson($symbol->json ?? JsonValue::literal('null')),
                TimTokenType::Affectation => self::name($stack, $named),
                TimTokenType::Operator => $stack[] = self::operate($symbol, $stack),
                TimTokenType::OpenParenFunc => $stack[] = self::call($symbol, $queue[++$at] ?? null, $stack, $named, $context, $memory),
                default => throw new TimError('rpn41'),
            };
        }

        return self::pop($stack);
    }

    /**
     * A named argument: its name under its value.
     *
     * @param list<TimValue> $stack
     * @param array<string, TimValue> $named
     */
    private static function name(array &$stack, array &$named): void
    {
        $value = self::pop($stack);
        $named[self::pop($stack)->text()] = $value;
    }

    /** @param list<TimValue> $stack */
    private static function operate(TimToken $symbol, array &$stack): TimValue
    {
        $second = self::pop($stack);
        $first = self::pop($stack);

        return $first->operate($symbol->surface, $second);
    }

    /**
     * @param list<TimValue> $stack
     * @param array<string, TimValue> $named the named arguments met since the last call, cleared
     */
    private static function call(TimToken $open, ?TimToken $name, array &$stack, array &$named, TimContext $context, TimMemory $memory): TimValue
    {
        $count = (int) $open->surface - \count($named);
        $function = (TimTokenType::FunctionName === $name?->type ? $context->functions->find($name->surface, $count) : null) ?? throw new TimError('Unknown built-in function '.($name->surface ?? ''));
        if (!$function->covers($count, [])) {
            throw new TimError('Bad number of arguments for '.$function->name());
        }
        /** @var list<TimValue> $arguments */
        $arguments = [];
        for ($i = 0; $i < $count; ++$i) {
            \array_unshift($arguments, self::pop($stack));
        }
        $given = $named;
        $named = [];

        return $function->call($context, $memory, $arguments, $given);
    }

    /**
     * The value on top of the stack, or the one under it, taken off.
     *
     * @param list<TimValue> $stack
     */
    private static function pop(array &$stack, int $offset = 0): TimValue
    {
        $index = \count($stack) - 1 - $offset;
        $value = $stack[$index] ?? throw new TimError('Fatal parsing error');
        \array_splice($stack, $index, length: 1);

        return $value;
    }
}
