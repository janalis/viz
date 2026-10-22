<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/**
 * One line of Creole read into atoms, as PlantUML's `StripeSimple`: an alignment it may open
 * with, its text in the font its markup gives each stretch — a heading's font made bigger
 * again for each stretch of markup within it, as PlantUML makes it — after its bullet or
 * number.
 */
final class CreoleStripe
{
    /** @var list<CreoleAtom> */
    private array $atoms = [];

    /**
     * @param string $type `normal`, `heading`, `bullet` or `number`
     * @param string $align `left`, `center` or `right`
     */
    public function __construct(
        public CreoleFont $font,
        private readonly string $type,
        private readonly int $order,
        public string $align,
        ?CreoleAtom $header = null,
    ) {
        if (null !== $header) {
            $this->atoms[] = $header;
        }
    }

    /** @return list<CreoleAtom> its atoms, a space when it has none */
    public function atoms(): array
    {
        return [] === $this->atoms ? [new CreoleWord(' ', $this->font)] : $this->atoms;
    }

    public function atom(CreoleAtom $atom): void
    {
        $this->atoms[] = $atom;
    }

    /** As `analyzeAndAdd`: the alignment taken off, the escaped characters hidden, then the text read. */
    public function add(string $line): void
    {
        foreach (['<left>' => 'left', '<l>' => 'left', '<center>' => 'center', '<c>' => 'center', '<right>' => 'right', '<r>' => 'right'] as $tag => $align) {
            if (!\str_starts_with($line, $tag)) {
                continue;
            }
            [$line, $this->align] = [\substr($line, \strlen($tag)), $align];
            break;
        }
        $line = self::hide($line);
        if ('heading' === $this->type) {
            $this->font = $this->font->with(match ($this->order) {
                0 => ['size' => $this->font->size + 4, 'bold' => true],
                1 => ['size' => $this->font->size + 2, 'bold' => true],
                2 => ['size' => $this->font->size + 1, 'bold' => true],
                default => ['italic' => true],
            });
        }
        $this->read($line);
    }

    /** A stretch read in another font, the font then put back. */
    public function styled(CreoleFont $font, string $text): void
    {
        $before = $this->font;
        $this->font = $font;
        $this->add($text);
        $this->font = $before;
    }

    /** As `modifyStripe`: each command where one starts, the text between them as words. */
    private function read(string $line): void
    {
        $pending = '';
        $at = 0;
        $length = \strlen($line);
        while ($at < $length) {
            $found = CreoleCommands::find($line, $at);
            if (null !== $found) {
                $this->word($pending);
                CreoleCommands::carry($this, ...$found);
                [$pending, $at] = ['', $at + \strlen($found[1][0])];
                continue;
            }
            $char = \mb_substr(\substr($line, $at), start: 0, length: 1);
            [$pending, $at] = [$pending.$char, $at + \max(1, \strlen($char))];
        }
        $this->word($pending);
    }

    private function word(string $text): void
    {
        if ('' !== $text) {
            $this->atoms[] = new CreoleWord(self::unhide(self::special($text)), $this->font);
        }
    }

    /** As `CharHidder`'s `hide`: a character escaped with `~` kept from the markup. */
    public static function hide(string $line): string
    {
        return (string) \preg_replace_callback(
            '/\\\\~|~([_\-"#\]\[*.\/<])|~(.)/su',
            static fn (array $match): string => match (true) {
                '\~' === $match[0] => \mb_chr(0xE000 + \ord('~')),
                '' !== ($match[1] ?? '') => \mb_chr(0xE000 + \ord($match[1])),
                default => $match[0],
            },
            $line,
        );
    }

    public static function unhide(string $text): string
    {
        return (string) \preg_replace_callback('/[\x{E000}-\x{E0FF}]/u', static fn (array $match): string => \mb_chr((int) \mb_ord($match[0]) - 0xE000), $text);
    }

    /** As `AtomText`'s `manageSpecialChars`: `&#…;` and `<U+…>` as their characters, `~@start` and `\t`. */
    private static function special(string $text): string
    {
        $text = (string) \preg_replace_callback(
            '/&#(\d+);|<U\+([0-9a-fA-F]{4,5})>/',
            static fn (array $match): string => (string) \mb_chr('' !== $match[1] ? (int) $match[1] : (int) \hexdec($match[2] ?? '0')),
            $text,
        );

        return \str_replace(['~@start', '\t'], ['@start', "\t"], $text);
    }
}
