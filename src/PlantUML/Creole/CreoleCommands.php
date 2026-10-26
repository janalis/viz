<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * Creole's markup within a line, as PlantUML's legacy `CommandCreoleBuilder` reads it: tried
 * by the two characters it starts with, in its order, each the first that matches taking its
 * stretch — styles and colours over the stretch they close, or to the line's end when they do
 * not; `""` monospaced; `[[…]]` links; `<space:…>`; raised and lowered text. A sprite this
 * diagram cannot hold is left out, as PlantUML leaves it; icons, emoji, images, QR codes and
 * formulas are left to PlantUML.
 */
final class CreoleCommands
{
    private const string COLOR = '(?::(?<c>#[0-9a-fA-F]{6}|\w+))?';
    private const string STRIKE = '(?:strike|STRIKE|s|S|del|DEL)';
    private const string SCALE = '(?:[{,]?(?:(?:scale=|\*)[0-9.]+)?(?:,?color[= :](?:#[0-9a-fA-F]{1,8}|\w+))?\}?)?';

    /** @var array<string, list<array{string, string}>> each starter's patterns and what they do, in PlantUML's order */
    private const array COMMANDS = [
        '**' => [['/\G\*\*(?<v>.+?)\*\*/u', 'bold']],
        '//' => [['/\G\/\/(?<v>.+?)\/\//u', 'italic']],
        '__' => [['/\G__(?<v>.+?)__/u', 'underline']],
        '--' => [['/\G--(?<v>.+?)--/u', 'strike']],
        '~~' => [['/\G~~(?<v>.+?)~~/u', 'wave']],
        '<b' => [
            ['/\G<[bB]>(?<v>.*?)<\/[bB]>/u',                                                              'bold'],
            ['/\G<[bB]>(?<v>.+)/u',                                                                       'bold'],
            ['/\G<[bB][aA][cC][kK](?::(?<c>#?\w+(?:[-\\\\|\/]#?\w+)?))?>(?<v>.*?)<\/[bB][aA][cC][kK]>/u', 'back'],
            ['/\G<[bB][aA][cC][kK](?::(?<c>#?\w+(?:[-\\\\|\/]#?\w+)?))?>(?<v>.+)/u',                      'back'],
        ],
        '<i' => [
            ['/\G<[iI]>(?<v>.*?)<\/[iI]>/u',                   'italic'],
            ['/\G<[iI]>(?<v>.+)/u',                            'italic'],
            ['/\G<img[\s:]+[^>{}]+(?:\{scale=[0-9.]+\})?>/iu', 'refuse'],
        ],
        '<p' => [
            ['/\G<[pP][lL][aA][iI][nN]>(?<v>.*?)<\/[pP][lL][aA][iI][nN]>/u', 'plain'],
            ['/\G<[pP][lL][aA][iI][nN]>(?<v>.+)/u',                          'plain'],
        ],
        '<u' => [['/\G<[uU]'.self::COLOR.'>(?<v>.*?)<\/[uU]>/u', 'underline'], ['/\G<[uU]'.self::COLOR.'>(?<v>.+)/u', 'underline']],
        '<s' => [
            ['/\G<'.self::STRIKE.self::COLOR.'>(?<v>.*?)<\/'.self::STRIKE.'>/u', 'strike'],
            ['/\G<'.self::STRIKE.self::COLOR.'>(?<v>.+)/u', 'strike'],
            ['/\G<size[\s:]+(?<n>\d+)\s*>(?<v>.*?)<\/size>/iu', 'size'],
            ['/\G<size[\s:]+(?<n>\d+)\s*>(?<v>.*)/iu', 'size'],
            ['/\G<sup>(?<v>.*?)<\/sup>/u', 'sup'],
            ['/\G<sub>(?<v>.*?)<\/sub>/u', 'sub'],
            ['/\G<space:(?<n>\d+)\/?>/iu', 'space'],
        ],
        '<d' => [['/\G<'.self::STRIKE.self::COLOR.'>(?<v>.*?)<\/'.self::STRIKE.'>/u', 'strike'], ['/\G<'.self::STRIKE.self::COLOR.'>(?<v>.+)/u', 'strike']],
        '<w' => [['/\G<[wW]'.self::COLOR.'>(?<v>.*?)<\/[wW]>/u', 'wave'], ['/\G<[wW]'.self::COLOR.'>(?<v>.+)/u', 'wave']],
        '<c' => [
            ['/\G<color[\s:]+(?<c>#[0-9a-fA-F]{1,6}|#?\w+)\s*>(?<v>.*?)<\/color>/iu', 'color'],
            ['/\G<color[\s:]+(?<c>#[0-9a-fA-F]{1,6}|#?\w+)\s*>(?<v>.*)/iu',           'color'],
        ],
        '<f' => [
            ['/\G<font(?:\s+size\s*=\s*"?(?<n>\d+)"?|\s+color\s*=\s*"?(?<c>#[0-9a-fA-F]{6}|\w+)"?)+\s*>(?<v>.*?)<\/font>/iu', 'font'],
            ['/\G<font(?:\s+size\s*=\s*"?(?<n>\d+)"?|\s+color\s*=\s*"?(?<c>#[0-9a-fA-F]{6}|\w+)"?)+\s*>(?<v>.*)/iu',          'font'],
            ['/\G<font[\s:]+(?<f>[^>]+?)\/?>(?<v>.*?)<\/font>/iu',                                                            'family'],
            ['/\G<font[\s:]+(?<f>[^>]+?)\/?>(?<v>.*)/iu',                                                                     'family'],
        ],
        '<q' => [['/\G<qrcode[\s:]+[^>{}]+(?:\{scale=[0-9.]+\})?>/iu', 'refuse']],
        '<#' => [['/\G<#\w+&[-\w]+'.self::SCALE.'>/iu', 'refuse'], ['/\G<#\w+:[0-9a-z][0-9_a-z]*:'.self::SCALE.'>/iu', 'refuse'], ['/\G<#\w+\$[-\p{L}0-9_\/.]+'.self::SCALE.'>/iu', 'sprite']],
        '<&' => [['/\G<&[-\w]+'.self::SCALE.'>/iu', 'refuse']],
        '<:' => [['/\G<:[0-9a-z][0-9_a-z]*:'.self::SCALE.'>/iu', 'refuse']],
        '<m' => [['/\G<math>.+?<\/math>/iu', 'refuse']],
        '<l' => [['/\G<latex>.+?<\/latex>/iu', 'refuse']],
        '<$' => [['/\G<\$[-\p{L}0-9_\/.]+'.self::SCALE.'>/iu', 'sprite']],
        '""' => [['/\G""(?<v>.*?)""/u', 'mono']],
        '[[' => [[
            '/\G\[\[\s*(?:"(?<q>[^"]+)"(?:\s*\{[^{}]*\})?(?:\s(?<ql>[^\s{}\[\]][^\[\]]*))?|\{(?<t>[^{}]*)\}\s*(?<tl>[^\s{}\[\]][^\[\]]*)?|(?<l>[^\s"\[\]]+?)(?:\s*\{[^{}]*\})?(?:\s(?<ll>[^\s{}\[\]][^\[\]]*))?)\s*\]\]/u',
            'url',
        ]],
        '<t' => [
            ['/\G<text[\s:]+[^>]+\/?>(?<v>.*?)<\/text>/iu', 'passthrough'],
            ['/\G<text[\s:]+[^>]+\/?>(?<v>.*)/iu',          'passthrough'],
        ],
    ];

    /**
     * The command starting at a place in a line, and what it matched; null when none starts there.
     *
     * @return ?array{string, array<int|string, string>}
     */
    public static function find(string $line, int $at): ?array
    {
        $rest = \substr($line, $at);
        if (\mb_strlen($rest) <= 2) {
            return null;
        }
        foreach (self::COMMANDS[\strtolower(\substr($rest, offset: 0, length: 2))] ?? [] as [$pattern, $kind]) {
            $match = [];
            if (1 !== \preg_match($pattern, $line, $match, flags: 0, offset: $at) || !self::taken($kind, $match)) {
                continue;
            }

            return [$kind, $match];
        }

        return null;
    }

    /** @param array<int|string, string> $match as each command's `matchingSize`: a style with nothing in it is not taken */
    private static function taken(string $kind, array $match): bool
    {
        return !\in_array($kind, ['bold', 'italic', 'underline', 'strike', 'wave', 'back', 'plain', 'sup', 'sub'], strict: true) || '' !== ($match['v'] ?? '');
    }

    /**
     * A command carried out on its stripe.
     *
     * @param array<int|string, string> $match
     * @throws Unsupported on markup left to PlantUML
     */
    public static function carry(CreoleStripe $stripe, string $kind, array $match): void
    {
        $font = $stripe->font;
        $value = $match['v'] ?? '';
        $color = '' === ($match['c'] ?? '') ? null : $match['c'];
        match ($kind) {
            'refuse' => throw new Unsupported('The native PlantUML renderer leaves '.$match[0].' to PlantUML'),
            'sprite' => null,
            'space' => $stripe->atom(new CreoleSpace(\floatval($match['n']))),
            'url' => $stripe->atom(new CreoleWord(self::label($match), $font->with(['color' => '#0000ff', 'underline' => '#0000ff']))),
            default => $stripe->styled(self::font($font, $kind, $color, $match), $value),
        };
    }

    /** @param array<int|string, string> $match */
    private static function font(CreoleFont $font, string $kind, ?string $color, array $match): CreoleFont
    {
        $extended = null === $color ? null : PlantUmlColor::parse($color) ?? '#ffffff';

        return match ($kind) {
            'bold' => $font->with(['bold' => true]),
            'italic' => $font->with(['italic' => true]),
            'plain' => $font->with(['bold' => false, 'italic' => false]),
            'underline' => $font->with(['underline' => $extended ?? $font->color]),
            'strike' => $font->with(['strike' => $extended ?? $font->color]),
            'wave' => $font->with(['wave' => $extended ?? $font->color]),
            'back' => null === $extended ? $font : $font->with(['back' => $extended]),
            'size' => $font->with(['size' => \floatval($match['n'])]),
            'color' => $font->with(['color' => PlantUmlColor::parse((string) $color) ?? $font->color]),
            'font' => $font->with(['size' => '' === ($match['n'] ?? '') ? $font->size : \floatval($match['n']), 'color' => PlantUmlColor::parse((string) $color) ?? $font->color]),
            'family' => $font->with(['family' => self::family($match['f'] ?? '')]),
            'mono' => $font->with(['family' => 'monospaced']),
            'sup', 'sub' => $font->with(['position' => $kind]),
            default => $font,
        };
    }

    /** The metrics a font family is measured with: Java's serif, monospaced or sans-serif. */
    public static function family(string $name): string
    {
        $name = \strtolower(\trim($name));

        return match (true) {
            \in_array($name, ['serif', 'times', 'times new roman'], strict: true) => 'serif',
            \in_array($name, ['monospaced', 'monospace', 'courier', 'courier new'], strict: true) => 'monospaced',
            default => 'sans',
        };
    }

    /** Text with each `[[…]]` link written as what it shows: its label, else its target. */
    public static function unlinked(string $text): string
    {
        if (!\str_contains($text, '[[')) {
            return $text;
        }
        $pattern = '/'.\substr(self::COMMANDS['[['][0][0], offset: 3);

        return (string) \preg_replace_callback($pattern, self::label(...), $text);
    }

    /** @param array<int|string, string> $match a link's label, else its target */
    private static function label(array $match): string
    {
        foreach (['ql', 'q', 'tl', 't', 'll', 'l'] as $group) {
            if ('' !== \trim($match[$group] ?? '')) {
                return \trim($match[$group]);
            }
        }

        return '';
    }
}
