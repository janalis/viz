<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\PlantUML\Draw\PlantUmlSprite;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/** Reads the commands every PlantUML diagram shares out of a block's lines; see {@see PlantUmlDocument}. */
final class PlantUmlDocumentReader
{
    /** What PlantUML's `CommandSkinParam` warns of, by the parameter written on its own line. */
    private const array WARNINGS = [
        'handwritten' => "Please use '!option handwritten true' to enable handwritten ",
        'participantpadding' => 'Please use CSS style instead of skinparam ParticipantPadding',
        'padding' => 'Please use CSS style instead of skinparam padding',
    ];

    /** A classifier opening its body. */
    private const string CLASSIFIER = '/^(?:abstract\s+class|abstract|class|interface|enum|annotation|entity|protocol|struct|exception|metaclass|stereotype|dataclass|record|object|map|json)\s.*\{$/i';
    /** A note whose text runs over the next lines: neither `:` text nor quoted text on its line. */
    private const string NOTE = '/^[hr]?note\b(?!.*[:"])/i';

    /** Shared commands the native renderer leaves to PlantUML. */
    private const string LEFT = '/^(?:sprite\b|rotate$|page\s|minwidth\b|skinparamlocked\b|assume\s+transparent|<style>\s*@import|\$\w+\s*=)/i';

    private int $at = 0;
    private StyleSheet $styles;
    /** @var array<string, string> */
    private array $params = [];
    /** @var array<string, PlantUmlCaption> */
    private array $captions = [];
    private float $scale = 1.0;
    /** @var ?array{?float, ?float, bool} */
    private ?array $fit = null;
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> */
    private array $kept = [];

    /** @param list<string> $lines */
    public function __construct(
        private readonly array $lines,
    ) {
        $this->styles = StyleSheet::skin();
    }

    /** @param array<string, string> $pragmas */
    public function read(array $pragmas, string $kind = 'uml'): PlantUmlDocument
    {
        $count = \count($this->lines);
        while ($this->at < $count) {
            $line = $this->lines[$this->at++];
            $trimmed = \trim($line);
            if ($this->sprite($trimmed)) {
                continue;
            }
            if (1 === \preg_match(self::LEFT, $trimmed)) {
                throw new Unsupported('The native PlantUML renderer leaves '.\explode(separator: ' ', string: $trimmed, limit: 2)[0].' to PlantUML');
            }
            if ($this->body($line)) {
                continue;
            }
            // A chart's own `legend` command, which PlantUML tries before the shared one, places its legend.
            if ('chart' === $kind && 1 === \preg_match('/^legend[\s\x{a0}]+(?:left|right|top|bottom)[\s\x{a0}]*$/iu', $trimmed)) {
                $this->kept[] = $line;
                continue;
            }
            if (!$this->style($trimmed) && !$this->skin($trimmed) && !$this->caption($trimmed) && !$this->scaled($trimmed)) {
                $this->kept[] = $line;
            }
        }

        return new PlantUmlDocument($this->kept, $this->styles, $this->params, $this->captions, $this->scale, $pragmas, $kind, $this->fit, \array_values(\array_unique($this->warnings)));
    }

    /**
     * A classifier's body up to its `}`, or a note over several lines up to `end note`, kept
     * whole: PlantUML's command for them takes every line, so none is a command of the document.
     */
    private function body(string $line): bool
    {
        $trimmed = \trim($line);
        $end = match (true) {
            1 === \preg_match(self::CLASSIFIER, $trimmed) => '/^\s*}\s*$/',
            1 === \preg_match(self::NOTE, $trimmed) => '/^\s*end\s?note\s*$/i',
            default => null,
        };
        if (null === $end) {
            return false;
        }
        $this->kept[] = $line;
        $count = \count($this->lines);
        while ($this->at < $count) {
            $inner = $this->lines[$this->at++];
            $this->kept[] = $inner;
            if (1 === \preg_match($end, $inner)) {
                break;
            }
        }

        return true;
    }

    /**
     * `sprite $name { … }` of 16 gray levels, `sprite $name [WxH/8] { … }` of 8 or 4, on one line
     * or up to `}`, `sprite $name [WxH/16z] data` compressed, and `sprite $name #library#sprite`
     * of the standard library; a sprite of colours or of SVG is left to PlantUML.
     */
    private function sprite(string $line): bool
    {
        $match = [];
        $name = '\$?([-.\p{L}\p{N}_]+)';
        if (1 === \preg_match('/^sprite\s+'.$name.'\s*(?:\[(\d+)x(\d+)\/(?:(\d+)(z)?|(color))\])?\s*\{$/u', $line, $match)) {
            $rows = \array_values(\array_filter(\array_map(\trim(...), $this->until('/^end\s?sprite|\}$/i')), static fn (string $row): bool => '' !== $row));
            $this->styles = $this->styles->withSprite($match[1], self::sprited($match, $rows));

            return true;
        }
        if (1 === \preg_match('/^sprite\s+\$([-\p{L}\p{N}_]+)\s*#([^#]+)#(\S+)$/u', $line, $match)) {
            $sprite = PlantUmlStdlib::sprite($match[2], $match[3]);
            $this->styles = null === $sprite ? $this->styles : $this->styles->withSprite($match[1], $sprite);

            return true;
        }
        if (1 === \preg_match('/^sprite\s+'.$name.'\s*(?:\[(\d+)x(\d+)\/(?:(\d+)(z)|(color))\])?\s+([-_A-Za-z0-9]+)$/u', $line, $match)) {
            $this->styles = $this->styles->withSprite($match[1], self::sprited($match, [$match[7]]));

            return true;
        }

        return false;
    }

    /**
     * @param array<array-key, string> $match the name, and width, height, levels, `z` and `color` when written
     * @param list<string> $rows
     */
    private static function sprited(array $match, array $rows): PlantUmlSprite
    {
        if ([] === $rows || '' !== ($match[6] ?? '')) {
            throw new Unsupported([] === $rows ? 'The PlantUML sprite '.$match[1].' has no data' : 'The native PlantUML renderer leaves sprites of colours to PlantUML');
        }
        if ('' === ($match[2] ?? '')) {
            return PlantUmlSprite::gray16($rows);
        }
        [$width, $height, $levels] = [(int) $match[2], (int) $match[3], (int) $match[4]];
        if (!\in_array($levels, [4, 8, 16], strict: true)) {
            throw new Unsupported('PlantUML allows sprites of 4, 8 or 16 gray levels only');
        }

        return match (true) {
            '' !== ($match[5] ?? '') => PlantUmlSprite::compressed($levels, $width, $height, \implode('', $rows)),
            16 === $levels => PlantUmlSprite::gray16($rows),
            default => PlantUmlSprite::packed($levels, $width, $height, $rows),
        };
    }

    /** `<style>` sheets, on one line or up to `</style>`. */
    private function style(string $line): bool
    {
        $match = [];
        if (1 === \preg_match('/^<style>(.*)<\/style>$/i', $line, $match)) {
            $this->styles = $this->styles->with($match[1]);

            return true;
        }
        if (1 !== \preg_match('/^<style>$/i', $line)) {
            return false;
        }
        $this->styles = $this->styles->with(\implode("\n", $this->until('/^<\/?style>$/i')));

        return true;
    }

    /** `skin`, `skinparam name value`, and `skinparam [name] {` blocks whose names prefix the ones inside. */
    private function skin(string $line): bool
    {
        $match = [];
        if (1 === \preg_match('/^skin[\s\x{a0}]+([\w.]+)$/iu', $line, $match)) {
            if (!\in_array(\strtolower($match[1]), ['rose', 'plantuml'], strict: true)) {
                throw new Unsupported('The native PlantUML renderer does not have the '.$match[1].' skin');
            }
            $this->styles = StyleSheet::skin($match[1]);

            return true;
        }
        if (1 === \preg_match('/^skinparam[\s\x{a0}]+([\w.]*(?:<<[^<>]*>>)?[\w.]*)[\s\x{a0}]+([^{}]*)$/iu', $line, $match)) {
            // As PlantUML's `CommandSkinParam`, which warns of these written so, and draws its warning above the diagram.
            $warning = self::WARNINGS[\strtolower($match[1])] ?? null;
            if (null !== $warning) {
                $this->warnings[] = $warning;
            }
            $this->param($match[1], \trim($match[2]));

            return true;
        }
        if (1 !== \preg_match('/^skinparam[\s\x{a0}]*(?:[\s\x{a0}]+([\w.]*(?:<<.*>>)?[\w.]*))?[\s\x{a0}]*\{$/iu', $line, $match)) {
            return false;
        }
        $context = [$match[1] ?? ''];
        foreach ($this->braced() as $inner) {
            $part = [];
            if ('' === $inner || 1 !== \preg_match('/^([\w.]*(?:<<.*>>)?[\w.]*)[\s\x{a0}]+(?:(\{)|(.*))$|^\}?$/u', $inner, $part)) {
                continue;
            }
            if ('}' === $inner || '' === ($part[0] ?? '')) {
                \array_pop($context);
                continue;
            }
            if ('{' === ($part[2] ?? '')) {
                $context[] = $part[1];
                continue;
            }
            $this->param(\implode('', $context).$part[1], \trim($part[3] ?? ''));
        }

        return true;
    }

    /** @throws Unsupported for the strict UML style, which changes how much is drawn across diagrams, and for padding around every text */
    private function param(string $name, string $value): void
    {
        if ('style' === \strtolower($name) && 'strictuml' === \strtolower($value)) {
            throw new Unsupported('The native PlantUML renderer leaves skinparam style strictuml to PlantUML');
        }
        if ('padding' === \strtolower($name) && \is_numeric($value) && 0.0 !== (float) $value) {
            throw new Unsupported('The native PlantUML renderer leaves skinparam padding to PlantUML');
        }
        $this->params[\strtolower($name)] = $value;
        $this->styles = $this->styles->withSkinParam($name, $value);
    }

    /** `title`, `caption`, `header`, `footer` and `legend`, on one line or up to their end. */
    private function caption(string $line): bool
    {
        $match = [];
        if (1 === \preg_match('/^(?:(left|right|center)[\s\x{a0}]*)?(header|footer)$/iu', $line, $match)) {
            $kind = \strtolower($match[2]);
            $this->captions[$kind] = new PlantUmlCaption($this->until('/^end[\s\x{a0}]?'.$kind.'$/iu'), '' === $match[1] ? null : \strtolower($match[1]));

            return true;
        }
        if (1 === \preg_match('/^(title|caption)$/i', $line, $match)) {
            $kind = \strtolower($match[1]);
            $this->captions[$kind] = new PlantUmlCaption($this->until('/^end[\s\x{a0}]?'.$kind.'$/iu'));

            return true;
        }
        if (1 === \preg_match('/^legend(?:[\s\x{a0}]+(top|bottom))?(?:[\s\x{a0}]+(left|right|center))?$/iu', $line, $match)) {
            $this->captions['legend'] = new PlantUmlCaption(
                $this->until('/^end[\s\x{a0}]?legend$/iu'),
                '' === ($match[2] ?? '') ? null : \strtolower($match[2]),
                '' === ($match[1] ?? '') ? null : \strtolower($match[1]),
            );

            return true;
        }
        if (1 === \preg_match('/^(?:(left|right|center)[\s\x{a0}]*)?(header|footer)(?=[\s\x{a0}:])(.*)$/iu', $line, $match)) {
            $text = PlantUmlDocument::text($match[3]);
            if (null !== $text) {
                $this->captions[\strtolower($match[2])] = new PlantUmlCaption(self::lines($text), '' === $match[1] ? null : \strtolower($match[1]));

                return true;
            }
        }
        // As PlantUML's `CommandMainframe`: a frame around the diagram, its label in a tab at its top left.
        if (1 === \preg_match('/^mainframe(?:[\s\x{a0}]*:[\s\x{a0}]*|[\s\x{a0}]+)(.*[\p{L}\p{N}_.].*)$/iu', $line, $match)) {
            $this->captions['mainframe'] = new PlantUmlCaption(self::lines($match[1]));

            return true;
        }
        if (1 !== \preg_match('/^(title|caption|legend)(?=[\s\x{a0}:])(.*)$/iu', $line, $match)) {
            return false;
        }
        $text = PlantUmlDocument::text($match[2]);
        if (null === $text) {
            return false;
        }
        $this->captions[\strtolower($match[1])] = new PlantUmlCaption(self::lines($text));

        return true;
    }

    /**
     * A caption written on one line, broken where `\n` breaks it, as PlantUML breaks it.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return \explode("\n", \str_replace(['\\n', '\\l', '\\r'], replace: "\n", subject: $text));
    }

    /** `scale 1.5`, `scale 2/3`, or to a size — `200 width`, `100 height`, `200*300`, `max` before any — the last written; a timing diagram's scale is its diagram's. */
    private function scaled(string $line): bool
    {
        $match = [];
        if (1 === \preg_match('/^scale[\s\x{a0}]+([0-9.]+)(?:[\s\x{a0}]*\/[\s\x{a0}]*([0-9.]+))?$/iu', $line, $match)) {
            $scale = \is_numeric($match[1]) ? (float) $match[1] : 0.0;
            $written = $match[2] ?? '1';
            $divisor = \is_numeric($written) ? (float) $written : 0.0;
            if ($scale <= 0 || $divisor <= 0) {
                throw new Unsupported('The PlantUML scale '.$match[0].' is not a number');
            }
            $this->scale = $scale / $divisor;
            $this->fit = null;

            return true;
        }
        if (1 === \preg_match('/^scale[\s\x{a0}]+(max[\s\x{a0}]+)?(?:(\d+)[\s\x{a0}]+(width|height)|(\d+)[\s\x{a0}]*\*[\s\x{a0}]*(\d+))[\s\x{a0}]*$/iu', $line, $match)) {
            $sizes = \array_map(static fn (string $size): ?float => \is_numeric($size) && (float) $size > 0 ? (float) $size : null, [$match[2] ?? '', $match[4] ?? '', $match[5] ?? '']);
            $this->fit = match (true) {
                'width' === \strtolower($match[3] ?? '') => [$sizes[0], null, '' !== ($match[1] ?? '')],
                'height' === \strtolower($match[3] ?? '') => [null, $sizes[0], '' !== ($match[1] ?? '')],
                default => [$sizes[1], $sizes[2], '' !== ($match[1] ?? '')],
            };
            $this->scale = 1.0;

            return true;
        }
        // A timing diagram's `scale 100 as 50 pixels` is its own.
        if (1 === \preg_match('/^scale[\s\x{a0}]+\d+(?:[\s\x{a0}]?[smhdDyY])?[\s\x{a0}]+as[\s\x{a0}]+\d+[\s\x{a0}]+pixels?[\s\x{a0}]*$/iu', $line)) {
            return false;
        }
        if (1 === \preg_match('/^scale\b/i', $line)) {
            throw new Unsupported('The native PlantUML renderer leaves the scale '.$line.' to PlantUML');
        }

        return false;
    }

    /**
     * The lines, trimmed, up to one that ends a block, which is read and left out.
     *
     * @return list<string>
     * @throws Unsupported when the block does not end
     */
    private function until(string $end): array
    {
        $lines = [];
        $count = \count($this->lines);
        while ($this->at < $count) {
            $line = \trim($this->lines[$this->at++]);
            if (1 === \preg_match($end, $line)) {
                return $lines;
            }
            $lines[] = $line;
        }

        throw new Unsupported('The PlantUML source leaves a block open');
    }

    /**
     * The lines of a braced block, trimmed, up to the brace that closes it: braces opened
     * inside close first.
     *
     * @return list<string>
     * @throws Unsupported when the block does not end
     */
    private function braced(): array
    {
        $lines = [];
        $depth = 0;
        $count = \count($this->lines);
        while ($this->at < $count) {
            $line = \trim($this->lines[$this->at++]);
            if ('}' === $line && 0 === $depth) {
                return $lines;
            }
            $depth += (int) \str_ends_with($line, '{') - (int) ('}' === $line);
            $lines[] = $line;
        }

        throw new Unsupported('The PlantUML source leaves a block open');
    }
}
