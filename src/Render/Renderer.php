<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** Turns diagram source into an image, synchronously. */
interface Renderer
{
    /** Whether this renderer can produce $format at all, before any source is read. */
    public function supports(Format $format): bool;

    /** @throws RenderFailure when the source is invalid or the tool behind the renderer fails */
    public function render(string $source, Format $format, RenderOptions $options = new RenderOptions()): Image;
}
