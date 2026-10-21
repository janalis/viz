<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A block of a wire diagram, as PlantUML's `WBlock` keeps one: where it stands in its parent,
 * the size it was given — or, with none, 100 by 100 when empty and the room its blocks take
 * otherwise — its blocks and printed lines, and a cursor where the next goes: 10 in, each
 * block 10 below the last, 10 more under it; a new column 10 right of what it holds.
 */
final class WireBlock
{
    /** As PlantUML's `WBlock.STARTING_Y`. */
    private const float STARTING_Y = 10.0;

    /** @var list<self> */
    public array $children = [];
    /** @var list<array{float, float, list<string>}> each printed text's top left, in the block, and its lines */
    public array $prints = [];
    private ?self $parent = null;
    /** @var array{float, float} */
    private array $cursor = [10.0, self::STARTING_Y];
    /** The block last added at the cursor, whose height the next one goes below. */
    private ?self $added = null;
    /** @var ?array{float, float} */
    private ?array $outHorizontal = null;
    /** @var ?array{float, float} */
    private ?array $outVertical = null;

    /** @param array{float, float} $position its top left in its parent */
    public function __construct(
        public readonly string $name,
        public readonly array $position = [0.0, 0.0],
        private readonly float $width = 0.0,
        private readonly float $height = 0.0,
        public readonly ?string $color = null,
    ) {}

    /** A block in it by its dotted path, such as `board.chip`. */
    public function find(string $path): ?self
    {
        $dot = \strpos($path, needle: '.');
        if (false === $dot) {
            return $this->child($path);
        }

        return $this->child(\substr($path, offset: 0, length: $dot))?->find(\substr($path, $dot + 1));
    }

    /**
     * A point of it, from its top left, on the whole diagram, each of `x` and `y` a number, a
     * share of its size as `50%`, or one and a number more as `100%-5`.
     *
     * @return array{float, float}
     */
    public function absolute(string $x = '0', string $y = '0'): array
    {
        if (null === $this->parent) {
            return $this->position;
        }
        [$parentX, $parentY] = $this->parent->absolute();
        [$width, $height] = $this->size();

        return [$this->position[0] + $parentX + self::measure($x, $width), $this->position[1] + $parentY + self::measure($y, $height)];
    }

    /** @return array{float, float} as PlantUML's `getMaxDimension` */
    public function size(): array
    {
        if ([] !== $this->children) {
            return 0.0 !== $this->width ? [$this->width, $this->height] : $this->natural();
        }

        return [0.0 === $this->width ? 100.0 : $this->width, 0.0 === $this->height ? 100.0 : $this->height];
    }

    /** A block at a depth under it, below the last one there. */
    public function add(int $level, string $name, float $width, float $height, ?string $color): void
    {
        if (null !== $this->child($name)) {
            throw new PlantUmlRefusal('Component exists already');
        }
        if ($level > 0) {
            $this->last()->add($level - 1, $name, $width, $height, $color);

            return;
        }
        $this->cursor[1] += 10;
        $block = new self($name, $this->next(), $width, $height, $color);
        $this->cursor[1] += 10;
        $this->added = $block;
        $block->parent = $this;
        $this->children[] = $block;
    }

    /** A new column at a depth: the cursor back up, 10 right of what the block there holds. */
    public function newColumn(int $level): void
    {
        if ($level > 0) {
            $this->last()->newColumn($level - 1);

            return;
        }
        $this->cursor = [$this->natural()[0] + 10, self::STARTING_Y];
        $this->added = null;
    }

    /** The cursor at a depth set to a point. */
    public function moveTo(int $level, float $x, float $y): void
    {
        if ($level > 0) {
            $this->last()->moveTo($level - 1, $x, $y);

            return;
        }
        $this->cursor = [$x, $y];
        $this->added = null;
    }

    /** The cursor at a depth moved by some way, the next block still below the last. */
    public function moveBy(int $level, float $x, float $y): void
    {
        if ($level > 0) {
            $this->last()->moveBy($level - 1, $x, $y);

            return;
        }
        $this->cursor = [$this->cursor[0] + $x, $this->cursor[1] + $y];
    }

    /**
     * Lines printed at a depth, at the cursor, which moves below them.
     *
     * @param list<string> $lines
     */
    public function print(int $level, array $lines, float $height): void
    {
        if ($level > 0) {
            $this->last()->print($level - 1, $lines, $height);

            return;
        }
        [$x, $y] = $this->next();
        $this->prints[] = [$x, $y, $lines];
        $this->cursor[1] += $height;
    }

    /**
     * Where its next link across leaves: the point given, or 5 down its right side, each next
     * one a space below the last: 15, or 25 below a bus.
     *
     * @return array{float, float}
     */
    public function outHorizontal(?string $x, ?string $y, float $space): array
    {
        $start = null !== $x && null !== $y ? $this->absolute($x, $y) : $this->outHorizontal ?? $this->absolute('100%', '5');
        $this->outHorizontal = [$start[0], $start[1] + $space];

        return $start;
    }

    /**
     * Where its next link down leaves: the point given, or 5 along its bottom, each next one
     * a space right of the last: 15, or 25 right of a bus.
     *
     * @return array{float, float}
     */
    public function outVertical(?string $x, ?string $y, float $space): array
    {
        $start = null !== $x && null !== $y ? $this->absolute($x, $y) : $this->outVertical ?? $this->absolute('5', '100%');
        $this->outVertical = [$start[0] + $space, $start[1]];

        return $start;
    }

    /** @return array{float, float} as PlantUML's `getNaturalDimension`: 10 past its farthest block */
    private function natural(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->children as $child) {
            [$childWidth, $childHeight] = $child->size();
            $width = \max($width, $child->position[0] + $childWidth + 10);
            $height = \max($height, $child->position[1] + $childHeight + 10);
        }

        return [$width, $height];
    }

    /** @return array{float, float} the cursor, below the block last added at it */
    private function next(): array
    {
        if (null !== $this->added) {
            $this->cursor[1] += $this->added->size()[1];
        }
        $this->added = null;

        return $this->cursor;
    }

    private function child(string $name): ?self
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /** The last block in it, as PlantUML's `getLastChild`, which it fails without. */
    private function last(): self
    {
        return $this->children[\count($this->children) - 1] ?? throw new PlantUmlRefusal('No block to indent under');
    }

    private static function measure(string $value, float $size): float
    {
        $percent = \strpos($value, needle: '%');
        if (false === $percent) {
            return self::number($value);
        }

        return (($size * self::number(\substr($value, offset: 0, length: $percent))) / 100) + self::number(\substr($value, $percent + 1));
    }

    /** A number as PlantUML's commands write one, nothing as 0. */
    public static function number(?string $value): float
    {
        return \is_numeric($value) ? (float) $value : 0.0;
    }
}
