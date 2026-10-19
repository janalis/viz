<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Render;

use Janalis\Viz\Render\FallbackRenderer;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Image;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\Renderer;
use Janalis\Viz\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

final class FallbackRendererTest extends TestCase
{
    public function testTheFirstRendererThatSucceedsAnswers(): void
    {
        $calls = [];
        $renderer = new FallbackRenderer([
            self::renderer('native', [Format::Svg], $calls, fails: true),
            self::renderer('cli', [Format::Svg], $calls),
            self::renderer('never', [Format::Svg], $calls),
        ]);

        $image = $renderer->render('source', Format::Svg, new RenderOptions(dpi: 144));

        $this->assertSame('cli:source:svg:144', $image->bytes);
        $this->assertSame(Format::Svg, $image->format);
        $this->assertSame(['native', 'cli'], $calls);
    }

    public function testARendererThatCannotProduceTheFormatIsSkipped(): void
    {
        $calls = [];
        $renderer = new FallbackRenderer([self::renderer('native', [Format::Svg], $calls), self::renderer('cli', [Format::Pdf], $calls)]);

        $this->assertSame('cli:x:pdf:', $renderer->render('x', Format::Pdf)->bytes);
        $this->assertSame(['cli'], $calls);
        $this->assertTrue($renderer->supports(Format::Svg));
        $this->assertTrue($renderer->supports(Format::Pdf));
        $this->assertFalse($renderer->supports(Format::Png));
    }

    public function testEveryFailureIsReportedWhenNoneSucceeds(): void
    {
        $calls = [];
        $renderer = new FallbackRenderer([self::renderer('native', [Format::Png], $calls, fails: true), self::renderer('cli', [Format::Png], $calls, fails: true)]);

        try {
            $renderer->render('x', Format::Png);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame("native failed\ncli failed", $failure->getMessage());
            $this->assertInstanceOf(RenderFailure::class, $failure->getPrevious());
            $this->assertSame('native failed', $failure->getPrevious()->getMessage());
        }
    }

    public function testNoCapableRendererSaysSo(): void
    {
        $calls = [];
        $this->expectExceptionObject(new RenderFailure('No renderer can produce Pdf'));

        new FallbackRenderer([self::renderer('native', [Format::Svg], $calls)])->render('x', Format::Pdf);
    }

    /**
     * @param list<Format>  $formats
     * @param list<string>  $calls
     */
    // @mago-expect lint:no-boolean-flag-parameter -- A test double's one switch.
    private static function renderer(string $name, array $formats, array &$calls, bool $fails = false): Renderer
    {
        return new class($name, $formats, $calls, $fails) implements Renderer {
            /**
             * @param list<Format> $formats
             * @param list<string> $calls
             */
            public function __construct(
                private readonly string $name,
                private readonly array $formats,
                private array &$calls,
                private readonly bool $fails,
            ) {}

            #[\Override]
            public function supports(Format $format): bool
            {
                return \in_array($format, $this->formats, strict: true);
            }

            #[\Override]
            public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
            {
                $this->calls[] = $this->name;
                if ($this->fails) {
                    throw new RenderFailure($this->name.' failed');
                }

                return new Image($this->name.':'.$source.':'.$format->value.':'.$options->dpi, $format);
            }
        };
    }
}
