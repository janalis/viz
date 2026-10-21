<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * PlantUML's `ElementMenuBar`: its entries 10 apart on a grey bar across its room; in the
 * second pass, each entry's menu open below the bar, a `-` in it a rule.
 */
final class SaltMenu implements SaltElement
{
    /** @var list<string> */
    private array $entries = [];
    /** @var array<string, list<string>> each opened entry's items, by its text */
    private array $menus = [];

    public function entry(string $text): void
    {
        $this->entries[] = $text;
    }

    public function item(string $entry, string $text): void
    {
        if (!\in_array($entry, $this->entries, strict: true)) {
            throw new PlantUmlRefusal('No menu entry '.$entry);
        }
        $this->menus[$entry][] = $text;
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->entries as $entry) {
            [$entryWidth, $entryHeight] = self::itemSize($entry);
            [$width, $height] = [$width + $entryWidth + 10, \max($height, $entryHeight)];
        }

        return [$width, $height];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        $marks = 0 === $pass ? [SaltPen::box($x, $y, $width, $height, '#dddddd')] : [];
        $left = $x;
        foreach ($this->entries as $entry) {
            if (0 === $pass) {
                \array_push($marks, ...new SaltLabel([$entry])->marks($left, $y));
            }
            if (1 === $pass && \array_key_exists($entry, $this->menus)) {
                \array_push($marks, ...self::menu($this->menus[$entry], $left, $y + $this->size()[1]));
            }
            $left += self::itemSize($entry)[0] + 10;
        }

        return $marks;
    }

    /**
     * PlantUML's `ElementMenuPopup`: its items one under another in a grey box.
     *
     * @param list<string> $items
     * @return list<\Janalis\Viz\Draw\Mark>
     */
    private static function menu(array $items, float $x, float $y): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($items as $item) {
            [$itemWidth, $itemHeight] = self::itemSize($item);
            [$width, $height] = [\max($width, $itemWidth), $height + $itemHeight];
        }
        $marks = [SaltPen::box($x, $y, $width, $height, '#dddddd')];
        foreach ($items as $item) {
            $itemHeight = self::itemSize($item)[1];
            if ('-' === $item) {
                $marks[] = SaltPen::line($x, $y + ($itemHeight / 2), $x + $width, $y + ($itemHeight / 2));
            }
            if ('-' !== $item) {
                \array_push($marks, ...new SaltLabel([$item])->marks($x, $y));
            }
            $y += $itemHeight;
        }

        return $marks;
    }

    /** @return array{float, float} an entry's or item's room, a `-` 10 by 5 */
    private static function itemSize(string $text): array
    {
        return '-' === $text ? [10.0, 5.0] : new SaltLabel([$text])->size();
    }
}
