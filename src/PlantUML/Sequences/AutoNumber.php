<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

/**
 * Message numbers as PlantUML's `autonumber` gives them: from a start, which may be dotted
 * like `1.1.1`, by a step, in a decimal format such as `<b>[000]` — zeros pad, `#` does not,
 * quoted text stands as written — and `stop`, `resume` and `inc` along the way. A dotted
 * number is shown bold, whole.
 */
final class AutoNumber
{
    private bool $running = true;

    /**
     * @param non-empty-list<int> $numbers
     * @param list<string> $separators between the numbers
     */
    private function __construct(
        private array $numbers,
        private array $separators,
        private int $step,
        private string $format,
    ) {}

    public static function start(?string $start, ?string $step, ?string $format): self
    {
        $numbers = [];
        $separators = [];
        $parts = [];
        \preg_match_all('/\d+|[^\d]+/', $start ?? '1', $parts);
        foreach ($parts[0] as $part) {
            \ctype_digit($part) ? ($numbers[] = (int) $part) : ($separators[] = $part);
        }

        return new self([] === $numbers ? [1] : $numbers, $separators, \is_numeric($step) ? (int) $step : 1, $format ?? '<b>0</b>');
    }

    /** The next message's number, or null when numbering is stopped. */
    public function next(): ?string
    {
        if (!$this->running) {
            return null;
        }
        $number = 1 === \count($this->numbers) && [] === $this->separators ? self::formatted($this->numbers[0], $this->format) : '<b>'.$this->dotted().'</b>';
        $this->numbers[\count($this->numbers) - 1] += $this->step;

        return $number;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function resume(?string $step, ?string $format): void
    {
        $this->running = true;
        $this->step = \is_numeric($step) ? (int) $step : $this->step;
        $this->format = $format ?? $this->format;
    }

    /** `autonumber inc A`, `inc B`: a dotted number's earlier part, the later ones back to one. */
    public function increment(?string $position): void
    {
        $at = null === $position ? \max(0, \count($this->numbers) - 2) : \min(\count($this->numbers) - 1, \ord(\strtoupper($position)) - \ord('A'));
        ++$this->numbers[$at];
        for ($later = $at + 1; $later < \count($this->numbers); ++$later) {
            $this->numbers[$later] = 1;
        }
    }

    private function dotted(): string
    {
        $text = '';
        foreach ($this->numbers as $index => $number) {
            $text .= $number.($this->separators[$index] ?? '');
        }

        return $text;
    }

    /** A number in a decimal format: its run of digits replaced, zeros padding it, quotes dropped. */
    public static function formatted(int $number, string $format): string
    {
        $match = [];
        if (1 !== \preg_match('/[0#,]*0[0#,]*|#+/', $format, $match)) {
            return \str_replace("'", replace: '', subject: $format).$number;
        }
        $digits = $match[0];
        $offset = (int) \strpos($format, $digits);
        $padded = \str_pad((string) $number, \substr_count($digits, needle: '0'), pad_string: '0', pad_type: \STR_PAD_LEFT);
        $literal = static fn (string $text): string => \str_replace("'", replace: '', subject: $text);

        return $literal(\substr($format, offset: 0, length: $offset)).$padded.$literal(\substr($format, $offset + \strlen($digits)));
    }
}
