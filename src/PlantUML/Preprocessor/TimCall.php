<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * The arguments of a call met in a line, as PlantUML's `EaterFunctionCall` reads them: for a
 * legacy `!define` or an unquoted function each as text, its variables and calls replaced;
 * for any other an expression; `name = value` a named one.
 */
final class TimCall
{
    /** @var list<TimValue> */
    public array $arguments = [];
    /** @var array<string, TimValue> */
    public array $named = [];
    /** How much of the line the call took, up to its `)`. */
    public int $length = 0;

    public function __construct(
        private readonly string $text,
        private readonly bool $legacy,
        private readonly bool $unquoted,
    ) {}

    public function read(TimContext $context, TimMemory $memory): void
    {
        $eater = new TimEater($this->text);
        $eater->skipUntil('(');
        $eater->expect('(');
        $eater->skipSpaces();
        if ($eater->accept(')')) {
            $this->length = $eater->position();

            return;
        }
        while (true) {
            $eater->skipSpaces();
            $this->argument($context, $memory, $eater);
            $eater->skipSpaces();
            $char = $eater->eat();
            if (',' === $char) {
                continue;
            }
            if (')' === $char) {
                break;
            }
            throw new TimError($this->unquoted ? 'unquoted function/procedure cannot use expression.' : 'call001', $this->text);
        }
        $this->length = $eater->position();
    }

    private function argument(TimContext $context, TimMemory $memory, TimEater $eater): void
    {
        $name = null;
        if (!$this->legacy && $eater->atAffectation()) {
            $name = $eater->name();
            $eater->skipSpaces();
            $eater->expect('=');
            $eater->skipSpaces();
        }
        $value = $this->legacy || $this->unquoted
            ? TimValue::string($context->substituted($memory, $eater->optionalQuoted()) ?? throw new TimError('Fatal parsing error', $this->text))
            : TimExpression::evaluate($eater->argument(), $context, $memory);
        if (null === $name) {
            $this->arguments[] = $value;

            return;
        }
        $this->named[$name] = $value;
    }
}
