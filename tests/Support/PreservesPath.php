<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/** A test that edits PATH to hide or plant a tool puts the original back. */
trait PreservesPath
{
    private string|false $pristinePath = false;

    #[Before]
    protected function rememberPath(): void
    {
        $this->pristinePath = \getenv('PATH');
    }

    #[After]
    protected function restorePath(): void
    {
        \putenv(false === $this->pristinePath ? 'PATH' : 'PATH='.$this->pristinePath);
    }
}
