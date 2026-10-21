<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `ElementDroplist`: its text, before any `^`, in a grey box with a button 12
 * wide holding a triangle down; the choices after it, if any, listed open below it — in
 * each pass, as PlantUML lists them.
 */
final readonly class SaltDroplist implements SaltElement
{
    private const float BOX = 12.0;

    private SaltField $field;
    private ?SaltLabel $open;

    public function __construct(string $text)
    {
        $caret = \strpos($text, needle: '^');
        $this->field = new SaltField(false === $caret ? $text : \substr($text, offset: 0, length: $caret));
        $choices = \array_slice(\array_values(\array_filter(\explode('^', $text), static fn (string $choice): bool => '' !== $choice)), offset: 1);
        $this->open = [] === $choices ? null : new SaltLabel($choices);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->field->size();

        return [$width + 4 + self::BOX, $height + 4];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        [$boxWidth, $boxHeight] = $this->size();
        $marks = [];
        if (0 === $pass) {
            $line = $x + $boxWidth - self::BOX;
            $marks = [
                SaltPen::box($x, $y, $boxWidth - 1, $boxHeight - 1, '#eeeeee'),
                ...$this->field->label->marks($x + 2, $y + 2),
                SaltPen::line($line, $y, $line, $y + $boxHeight - 1),
                SaltPen::shape([[0.0, 0.0], [self::BOX - 6, 0.0], [(self::BOX - 6) / 2, $this->field->label->size()[1] - 8]], $line + 3, $y + 6),
            ];
        }
        if (null === $this->open) {
            return $marks;
        }
        [$openWidth, $openHeight] = $this->open->size();
        $top = $y + $boxHeight - 1;

        return [...$marks, SaltPen::box($x, $top, \max($openWidth, $boxWidth - 1) - 1, $openHeight - 1, '#eeeeee'), ...$this->open->marks($x, $top)];
    }
}
