<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/**
 * Renderers tried in order until one produces the image: a native renderer first,
 * then the command-line tool it stands in for. Every failure is kept, so the final
 * error says why each one declined.
 */
final readonly class FallbackRenderer implements Renderer
{
    /** @param list<Renderer> $renderers */
    public function __construct(
        private array $renderers,
    ) {}

    #[\Override]
    public function supports(Format $format): bool
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($format)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image
    {
        $failures = [];
        foreach ($this->renderers as $renderer) {
            if (!$renderer->supports($format)) {
                continue;
            }
            try {
                return $renderer->render($source, $format, $options);
            } catch (RenderFailure $failure) {
                $failures[] = $failure;
            }
        }

        throw RenderFailure::exhausted($format, $failures);
    }
}
