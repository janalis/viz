<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `AbstractElementText`: a widget's text, trimmed, as wide as it is or 8 a
 * character as written, spaces kept, an icon two characters and markup none.
 */
final readonly class SaltField
{
    public SaltLabel $label;
    private int $characters;

    public function __construct(string $text)
    {
        $this->characters = \mb_strlen((string) \preg_replace(
            '/<\/?(?:b|i|u|s|w|strike|sub|sup|plain|code|color(?::[^>]*)?|back(?::[^>]*)?|size(?::[^>]*)?|font(?:[: ][^>]*)?|img[^>]*|\$[^>]*)>/i',
            replacement: '',
            subject: (string) \preg_replace('/<&[-\w]+>/', replacement: '00', subject: $text),
        ));
        $this->label = new SaltLabel([\trim($text)]);
    }

    /** @return array{float, float} */
    public function size(): array
    {
        [$width, $height] = $this->label->size();

        return [\max($width, $this->characters * 8), $height];
    }
}
