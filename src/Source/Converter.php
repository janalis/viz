<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Render\RenderFailure;

/**
 * Moves a diagram between the three languages through the shared models. A diagram
 * goes across in its own family when the target language has that family, and as
 * a plain graph of nodes and edges otherwise; a family with no graph form, such as
 * a sequence, reaches only the languages that have it.
 */
final readonly class Converter
{
    public function __construct(
        private Languages $languages = new Languages(),
    ) {}

    /** @throws UnsupportedConversion|RenderFailure */
    public function convert(string $source, SourceFormat $from, SourceFormat $to): string
    {
        return $this->write($this->read($source, $from), $to);
    }

    /** @throws UnsupportedConversion|RenderFailure */
    public function read(string $source, SourceFormat $format): Diagram
    {
        try {
            return $this->languages->reader($format)->read($source);
        } catch (ParseError|\InvalidArgumentException $exception) {
            throw new UnsupportedConversion('The '.$format->label().' source cannot be read: '.$exception->getMessage(), previous: $exception);
        }
    }

    /** @throws UnsupportedConversion when the diagram has no form in the language */
    public function write(Diagram $diagram, SourceFormat $format): string
    {
        $writer = $this->languages->writer($format);
        $graph = $diagram->graph();

        return match (self::route($diagram->family(), $writer)) {
            Route::Direct => $writer->write($diagram),
            Route::ViaGraph => null === $graph ? throw self::refusal($diagram->family(), $format) : $writer->write($graph),
            Route::Unsupported => throw self::refusal($diagram->family(), $format),
        };
    }

    /** How a diagram of $family reaches the language $writer writes. */
    public static function route(Family $family, Writer $writer): Route
    {
        return match (true) {
            \in_array($family, $writer->families(), strict: true) => Route::Direct,
            Family::Sequence !== $family && \in_array(Family::Graph, $writer->families(), strict: true) => Route::ViaGraph,
            default => Route::Unsupported,
        };
    }

    private static function refusal(Family $family, SourceFormat $format): UnsupportedConversion
    {
        return new UnsupportedConversion('A '.$family->value.' diagram has no '.$format->label().' form');
    }
}
