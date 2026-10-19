<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/**
 * Numbered PDF objects serialized with an exact cross-reference table. Nothing time- or
 * randomness-dependent is written, so the same objects always give the same bytes.
 */
final class PdfDocument
{
    /** @var array<int, string> object bodies by object number */
    private array $objects = [];
    private int $count = 0;
    public readonly bool $compress;

    public function __construct(?bool $compress = null)
    {
        $this->compress = $compress ?? \function_exists('gzcompress');
    }

    /** A number for an object whose body is written later, once the objects it names exist. */
    public function reserve(): int
    {
        return ++$this->count;
    }

    public function define(int $id, string $body): void
    {
        $this->objects[$id] = $body;
    }

    public function add(string $body): int
    {
        $id = $this->reserve();
        $this->define($id, $body);

        return $id;
    }

    /** A stream object, deflated when compression is on. */
    public function stream(string $dictionary, string $data): int
    {
        return $this->compress ? $this->encoded($dictionary.' /Filter /FlateDecode', (string) \gzcompress($data)) : $this->encoded($dictionary, $data);
    }

    /** A stream object whose data is already in its final encoding, which the dictionary names (a JPEG, say). */
    public function encoded(string $dictionary, string $data): int
    {
        return $this->add('<< '.\ltrim($dictionary.' /Length '.\strlen($data)).' >>'."\nstream\n".$data."\nendstream");
    }

    /** The file: header, objects, cross-reference table and trailer naming the catalog. */
    public function bytes(int $root, string $producer): string
    {
        $info = $this->add('<< /Producer '.self::literal($producer).' >>');
        // The binary comment line tells transfer tools the file is not text.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = '';
        for ($id = 1; $id <= $this->count; ++$id) {
            $offsets .= \sprintf("%010d 00000 n \n", \strlen($pdf));
            $pdf .= $id." 0 obj\n".($this->objects[$id] ?? 'null')."\nendobj\n";
        }
        $xref = \strlen($pdf);

        return (
            $pdf
            .'xref'
            ."\n0 "
            .($this->count + 1)
            ."\n0000000000 65535 f \n"
            .$offsets
            .'trailer'
            ."\n<< /Size "
            .($this->count + 1)
            .' /Root '
            .$root
            .' 0 R /Info '
            .$info
            ." 0 R >>\nstartxref\n"
            .$xref
            ."\n%%EOF\n"
        );
    }

    /** A literal string: delimiters and backslashes escaped, everything outside printable ASCII in octal. */
    public static function literal(string $text): string
    {
        $escaped = '';
        foreach (\str_split($text) as $byte) {
            $code = \ord($byte);
            $escaped .= match (true) {
                '(' === $byte || ')' === $byte || '\\' === $byte => '\\'.$byte,
                $code < 0x20 || $code > 0x7E => \sprintf('\\%03o', $code),
                default => $byte,
            };
        }

        return '('.$escaped.')';
    }

    /** A real number without exponent or negative zero, to four decimals. */
    public static function number(float $value): string
    {
        $text = \rtrim(\rtrim(\sprintf('%.4F', \is_finite($value) ? $value : 0.0), characters: '0'), characters: '.');

        return '-0' === $text ? '0' : $text;
    }
}
